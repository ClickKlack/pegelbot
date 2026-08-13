#!/usr/bin/env bash
#
# ============================================================================
#  Ausrollen des Pegelbots auf den Zielserver.
#
#      scripts/deploy.sh [--dry-run] [--skip-tests] [--allow-unpushed] [--yes]
#
#  Grundgedanke: Uebertragen wird ein Baum, der mit "git archive" aus dem
#  aktuellen Commit erzeugt wird. Damit kann nichts Unversioniertes auf den
#  Server gelangen - weder eine vergessene Arbeitsdatei noch eine
#  Konfiguration mit Zugangsdaten. Die Pruefung "ist alles im Git" ist also
#  keine zusaetzliche Abfrage, sondern die Bauweise selbst.
#
#  Die Abhaengigkeiten werden oertlich mit --no-dev gebaut und mituebertragen.
#  Der Server braucht deshalb kein Composer, und es laufen dort garantiert
#  dieselben Fassungen, gegen die die Tests gelaufen sind.
#
#  Konfiguration: scripts/deploy.conf (nicht versioniert),
#  Vorlage: scripts/deploy.conf.sample
# ============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
#  Ausgabe
# ---------------------------------------------------------------------------

if [[ -t 1 ]]; then
    C_INFO=$'\033[0;36m'; C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'
    C_ERR=$'\033[0;31m';  C_DIM=$'\033[2m';   C_OFF=$'\033[0m'
else
    C_INFO=""; C_OK=""; C_WARN=""; C_ERR=""; C_DIM=""; C_OFF=""
fi

step()  { printf '\n%s==>%s %s\n' "$C_INFO" "$C_OFF" "$1"; }
ok()    { printf '    %s✓%s %s\n' "$C_OK" "$C_OFF" "$1"; }
warn()  { printf '    %s!%s %s\n' "$C_WARN" "$C_OFF" "$1"; }
note()  { printf '    %s%s%s\n' "$C_DIM" "$1" "$C_OFF"; }
fail()  { printf '\n%sAbbruch:%s %s\n\n' "$C_ERR" "$C_OFF" "$1" >&2; exit 1; }

# ---------------------------------------------------------------------------
#  Aufrufoptionen
# ---------------------------------------------------------------------------

DRY_RUN=0
SKIP_TESTS=0
ALLOW_UNPUSHED=0
ASSUME_YES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)        DRY_RUN=1 ;;
        --skip-tests)     SKIP_TESTS=1 ;;
        --allow-unpushed) ALLOW_UNPUSHED=1 ;;
        --yes|-y)         ASSUME_YES=1 ;;
        --help|-h)
            sed -n '3,20p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) fail "Unbekannte Option: $1" ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
#  Projektwurzel und Konfiguration
# ---------------------------------------------------------------------------

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || fail "Kein Git-Arbeitsverzeichnis gefunden."
cd "$REPO_ROOT"

CONFIG_FILE="scripts/deploy.conf"

[[ -f "$CONFIG_FILE" ]] \
    || fail "$CONFIG_FILE fehlt. Anlegen mit: cp scripts/deploy.conf.sample $CONFIG_FILE"

# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${DEPLOY_HOST:?DEPLOY_HOST ist in $CONFIG_FILE nicht gesetzt}"
: "${DEPLOY_PATH:?DEPLOY_PATH ist in $CONFIG_FILE nicht gesetzt}"
REMOTE_PHP="${REMOTE_PHP:-php}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
DEPLOY_URL="${DEPLOY_URL:-}"

[[ "$DEPLOY_PATH" == /* ]] || fail "DEPLOY_PATH muss ein absoluter Pfad sein."
[[ "$DEPLOY_PATH" != "/" ]] || fail "DEPLOY_PATH darf nicht das Wurzelverzeichnis sein."

# Dateien, die auf dem Server liegen bleiben muessen: Konfiguration mit
# Zugangsdaten sowie Laufzeitdaten. Sie stehen nicht im Git und wuerden von
# rsync --delete sonst entfernt.
PRESERVE=(
    "bot/config/pegelbot-config.php"
    "logviewer/config.php"
    "bot/logs/"
    "bot/tmp/"
    "logviewer/var/"
)

# ---------------------------------------------------------------------------
#  1. Zustand des Arbeitsverzeichnisses
# ---------------------------------------------------------------------------

step "Zustand des Arbeitsverzeichnisses"

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[[ "$CURRENT_BRANCH" == "$DEPLOY_BRANCH" ]] \
    || fail "Zweig ist '$CURRENT_BRANCH', erwartet wird '$DEPLOY_BRANCH'."
ok "Zweig: $CURRENT_BRANCH"

if [[ -n "$(git status --porcelain)" ]]; then
    git status --short | sed 's/^/      /'
    fail "Es liegen nicht uebernommene Aenderungen vor. Ausgerollt wird immer der aktuelle Commit."
fi
ok "Arbeitsverzeichnis ist sauber"

# Der ausgerollte Stand soll nachvollziehbar bleiben. Fehlt er auf der
# Gegenstelle, laesst sich spaeter nicht mehr rekonstruieren, was laeuft.
if git rev-parse --abbrev-ref '@{upstream}' >/dev/null 2>&1; then
    if [[ -n "$(git log '@{upstream}..HEAD' --oneline)" ]]; then
        if [[ $ALLOW_UNPUSHED -eq 1 ]]; then
            warn "Nicht uebertragene Commits vorhanden - auf Wunsch fortgesetzt"
        else
            git log '@{upstream}..HEAD' --oneline | sed 's/^/      /'
            fail "Diese Commits sind nicht uebertragen. Erst 'git push', oder --allow-unpushed."
        fi
    else
        ok "Alle Commits sind uebertragen"
    fi
else
    warn "Kein Gegenstellenzweig eingerichtet - Nachvollziehbarkeit eingeschraenkt"
fi

COMMIT="$(git rev-parse --short HEAD)"
note "Auszurollender Stand: $COMMIT $(git log -1 --format=%s)"

# ---------------------------------------------------------------------------
#  2. Geheimnisse
# ---------------------------------------------------------------------------

step "Geheimnisse"

SECRET_FILES=(
    "scripts/deploy.conf"
    "bot/config/pegelbot-config.php"
    "logviewer/config.php"
)

for f in "${SECRET_FILES[@]}"; do
    if git ls-files --error-unmatch "$f" >/dev/null 2>&1; then
        fail "$f steht im Git-Index. Entfernen mit 'git rm --cached $f' und in .gitignore aufnehmen."
    fi
done
ok "Keine Konfigurationsdatei mit Zugangsdaten im Git-Index"

# Der uebertragene Baum stammt aus git archive; hier wird zusaetzlich geprueft,
# dass darin wirklich keine dieser Dateien auftaucht.
ARCHIVE_LIST="$(git ls-tree -r --name-only HEAD)"
for f in "${SECRET_FILES[@]}"; do
    if grep -qxF "$f" <<< "$ARCHIVE_LIST"; then
        fail "$f ist Teil des Commits. Ausrollen abgebrochen."
    fi
done
ok "Auszurollender Baum enthaelt keine Zugangsdaten"

# ---------------------------------------------------------------------------
#  3. Oertliche Pruefungen
# ---------------------------------------------------------------------------

step "Oertliche Pruefungen"

command -v composer >/dev/null || fail "composer nicht gefunden."
command -v rsync    >/dev/null || fail "rsync nicht gefunden."
command -v ssh      >/dev/null || fail "ssh nicht gefunden."

composer validate --strict --no-interaction --quiet \
    || fail "composer.json ist nicht in Ordnung."
ok "composer.json ist gueltig"

if [[ $SKIP_TESTS -eq 1 ]]; then
    warn "Tests uebersprungen"
else
    [[ -d vendor ]] || composer install --no-interaction --quiet
    if ./vendor/bin/phpunit --no-output >/dev/null 2>&1; then
        TEST_COUNT="$(./vendor/bin/phpunit --list-tests 2>/dev/null | grep -c ' - ' || true)"
        ok "Testsuite gruen (${TEST_COUNT:-?} Tests)"
    else
        ./vendor/bin/phpunit || true
        fail "Testsuite rot. Es wird nichts ausgerollt."
    fi
fi

if composer audit --no-interaction --format=summary 2>&1 | grep -q "No security vulnerability"; then
    ok "Keine bekannten Schwachstellen in den Abhaengigkeiten"
else
    warn "composer audit meldet Schwachstellen:"
    composer audit --no-interaction --format=summary 2>&1 | sed 's/^/      /' | head -5
fi

# ---------------------------------------------------------------------------
#  4. Auslieferungsbaum bauen
# ---------------------------------------------------------------------------

step "Auslieferungsbaum bauen"

BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/pegelbot-deploy.XXXXXX")"
trap 'rm -rf "$BUILD_DIR"' EXIT

# mktemp legt mit 700 an, und "rsync -a quelle/ ziel/" uebertraegt die Rechte des
# Quellverzeichnisses auf das Ziel. Ohne diese Zeile stand das Zielverzeichnis auf
# dem Server auf 700, der Webserver kam auf keiner Ebene mehr durch und
# beantwortete jeden Pfad mit 403.
chmod 755 "$BUILD_DIR"

git archive --format=tar HEAD | tar -x -C "$BUILD_DIR"
ok "Baum aus Commit $COMMIT erzeugt ($(find "$BUILD_DIR" -type f | wc -l | tr -d ' ') Dateien)"

composer install --no-dev --optimize-autoloader --no-interaction --quiet \
    --working-dir="$BUILD_DIR" \
    || fail "Bauen der Abhaengigkeiten fehlgeschlagen."
ok "Abhaengigkeiten ohne Entwicklungspakete gebaut"

# Die Testsuite wird produktiv nicht gebraucht und liefe ohne PHPUnit ohnehin
# nicht. Sie bleibt draussen, damit auf dem Server nur steht, was dort wirkt.
rm -rf "$BUILD_DIR/tests" "$BUILD_DIR/scripts" "$BUILD_DIR/phpunit.xml"

# Verzeichnisse fuer Laufzeitdaten anlegen, damit sie beim Erstausrollen da sind
mkdir -p "$BUILD_DIR/bot/logs" "$BUILD_DIR/bot/tmp" "$BUILD_DIR/logviewer/var/auth"

BUILD_SIZE="$(du -sh "$BUILD_DIR" | cut -f1)"
note "Umfang: $BUILD_SIZE"

# ---------------------------------------------------------------------------
#  5. Gegenstelle
# ---------------------------------------------------------------------------

step "Gegenstelle pruefen"

ssh -o BatchMode=yes -o ConnectTimeout=10 "$DEPLOY_HOST" true 2>/dev/null \
    || fail "Keine Verbindung zu '$DEPLOY_HOST'. SSH-Alias und Schluessel pruefen."
ok "Verbindung zu $DEPLOY_HOST steht"

REMOTE_PHP_VERSION="$(ssh "$DEPLOY_HOST" "$REMOTE_PHP -r 'echo PHP_VERSION;'" 2>/dev/null || true)"
[[ -n "$REMOTE_PHP_VERSION" ]] \
    || fail "'$REMOTE_PHP' auf dem Server nicht ausfuehrbar. REMOTE_PHP in $CONFIG_FILE pruefen."
ok "PHP auf dem Server: $REMOTE_PHP_VERSION"

# Die oertlich aufgeloesten Pakete gelten fuer eine bestimmte PHP-Fassung.
# Weicht der Server davon ab, kann Unpassendes ausgeliefert worden sein.
PLATFORM_PHP="$(php -r '$j=json_decode(file_get_contents("composer.json"),true); echo $j["config"]["platform"]["php"] ?? "";')"
if [[ -n "$PLATFORM_PHP" ]]; then
    if [[ "${REMOTE_PHP_VERSION%%-*}" == "$PLATFORM_PHP" ]]; then
        ok "Stimmt mit config.platform.php ueberein ($PLATFORM_PHP)"
    else
        warn "config.platform.php ist $PLATFORM_PHP, der Server faehrt $REMOTE_PHP_VERSION"
        note "Bei abweichender Nebenversion die Festlegung in composer.json angleichen."
    fi
fi

ssh "$DEPLOY_HOST" "mkdir -p '$DEPLOY_PATH'" || fail "Zielverzeichnis nicht anlegbar."
ok "Zielverzeichnis vorhanden: $DEPLOY_PATH"

# ---------------------------------------------------------------------------
#  6. Uebertragen
# ---------------------------------------------------------------------------

RSYNC_ARGS=(-az --delete --human-readable --itemize-changes)
for p in "${PRESERVE[@]}"; do
    RSYNC_ARGS+=("--exclude=/$p")
done

if [[ $DRY_RUN -eq 1 ]]; then
    step "Uebertragen - Probelauf, es wird nichts geaendert"
    RSYNC_ARGS+=(--dry-run)
else
    step "Uebertragen"
    if [[ $ASSUME_YES -eq 0 ]]; then
        printf '    Ziel: %s:%s\n' "$DEPLOY_HOST" "$DEPLOY_PATH"
        printf '    Stand: %s\n' "$COMMIT"
        read -r -p "    Fortfahren? [j/N] " answer
        [[ "$answer" =~ ^[jJyY]$ ]] || fail "Auf Wunsch abgebrochen."
    fi
fi

rsync "${RSYNC_ARGS[@]}" "$BUILD_DIR/" "$DEPLOY_HOST:$DEPLOY_PATH/" \
    | sed 's/^/      /'

if [[ $DRY_RUN -eq 1 ]]; then
    printf '\n%sProbelauf beendet.%s Es wurde nichts veraendert.\n\n' "$C_OK" "$C_OFF"
    exit 0
fi
ok "Uebertragung abgeschlossen"

# ---------------------------------------------------------------------------
#  7. Nachlauf auf dem Server
# ---------------------------------------------------------------------------

step "Nachlauf auf dem Server"

ssh "$DEPLOY_HOST" "
    set -e
    cd '$DEPLOY_PATH'
    mkdir -p bot/logs bot/tmp logviewer/var/auth
    chmod 700 logviewer/var/auth
" || fail "Anlegen der Laufzeitverzeichnisse fehlgeschlagen."
ok "Laufzeitverzeichnisse vorhanden, var/auth auf 700"

# Der Webserver muss den Pfad bis zum Dokumentenstamm durchlaufen koennen.
# Fehlt das Durchgangsrecht auf einer Ebene, antwortet Apache auf jeden Pfad
# mit 403 - auch auf nicht vorhandene, was die Suche in die Irre fuehrt.
ssh "$DEPLOY_HOST" "chmod o+x '$DEPLOY_PATH' '$DEPLOY_PATH/logviewer' '$DEPLOY_PATH/logviewer/public'" \
    || fail "Durchgangsrechte konnten nicht gesetzt werden."
ok "Durchgangsrechte zum Dokumentenstamm gesetzt"

MISSING=""
for f in "bot/config/pegelbot-config.php" "logviewer/config.php"; do
    if ! ssh "$DEPLOY_HOST" "test -f '$DEPLOY_PATH/$f'"; then
        MISSING="$MISSING $f"
    fi
done

if [[ -n "$MISSING" ]]; then
    warn "Konfiguration fehlt auf dem Server:$MISSING"
    note "Aus der jeweiligen *.sample-Datei anlegen. Bis dahin laeuft nichts."
else
    ok "Beide Konfigurationsdateien liegen auf dem Server"

    # Bewusst nur bootstrap.php: Ein vollstaendiger Lauf wuerde echte
    # Benachrichtigungen an alle Abonnenten verschicken.
    if ssh "$DEPLOY_HOST" "cd '$DEPLOY_PATH' && $REMOTE_PHP -r 'require \"bot/bootstrap.php\"; echo \"ok\";'" 2>/dev/null | grep -q "ok"; then
        ok "Bot startet, Datenbankverbindung steht"
    else
        fail "bot/bootstrap.php laeuft auf dem Server nicht durch. Konfiguration und Datenbank pruefen."
    fi
fi

# ---------------------------------------------------------------------------
#  8. Der Betrachter aus Sicht des Webservers
# ---------------------------------------------------------------------------
#
# Diese Pruefung ist oertlich nicht zu ersetzen: Der eingebaute PHP-Webserver
# wertet keine .htaccess aus. Fehler in Rechten oder Apache-Regeln zeigen sich
# erst hier.

if [[ -n "$DEPLOY_URL" ]]; then
    step "Log-Betrachter abrufen"

    if ! command -v curl >/dev/null; then
        warn "curl nicht gefunden, Abruf uebersprungen"
    else
        HTTP_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$DEPLOY_URL/" 2>/dev/null || echo 000)"

        case "$HTTP_CODE" in
            200)
                ok "$DEPLOY_URL antwortet mit 200"
                ;;
            403)
                warn "$DEPLOY_URL antwortet mit 403"
                note "Meist ein fehlendes Durchgangsrecht auf einer Verzeichnisebene"
                note "oder eine zu weit gefasste Regel in logviewer/public/.htaccess."
                ;;
            500)
                warn "$DEPLOY_URL antwortet mit 500"
                note "Fehlender Kennwort-Hash in logviewer/config.php oder ein"
                note "Syntaxfehler in der .htaccess."
                ;;
            000)
                warn "$DEPLOY_URL nicht erreichbar"
                ;;
            *)
                warn "$DEPLOY_URL antwortet mit $HTTP_CODE"
                ;;
        esac

        # Die Konfiguration liegt oberhalb des Dokumentenstamms und darf von
        # aussen nicht erreichbar sein.
        LEAK_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$DEPLOY_URL/config.php" 2>/dev/null || echo 000)"
        if [[ "$LEAK_CODE" == "200" ]]; then
            fail "$DEPLOY_URL/config.php ist abrufbar. Der Dokumentenstamm zeigt auf logviewer/ statt auf logviewer/public/."
        fi
        ok "config.php ist von aussen nicht erreichbar (HTTP $LEAK_CODE)"
    fi
else
    note "DEPLOY_URL nicht gesetzt - Abruf des Betrachters uebersprungen"
fi

# ---------------------------------------------------------------------------
#  Abschluss
# ---------------------------------------------------------------------------

printf '\n%sAusgerollt:%s %s nach %s:%s\n' "$C_OK" "$C_OFF" "$COMMIT" "$DEPLOY_HOST" "$DEPLOY_PATH"

cat <<HINWEIS

  Einmalig auf dem Server einzurichten, falls noch nicht geschehen:

    Cron         5 * * * * $REMOTE_PHP $DEPLOY_PATH/bot/main.php > /dev/null 2>&1
    Dokstamm     Log-Subdomain auf $DEPLOY_PATH/logviewer/public/

  Kein vollstaendiger Botlauf wurde ausgeloest - das wuerde echte
  Benachrichtigungen verschicken. Zum Prueflauf von Hand:

    ssh $DEPLOY_HOST '$REMOTE_PHP $DEPLOY_PATH/bot/main.php'

HINWEIS
