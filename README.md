# Pegelbot

Ein Bot, der Wasserstände der Elbe von der offiziellen PEGELONLINE-Schnittstelle der
Wasserstraßen- und Schifffahrtsverwaltung des Bundes abruft, historisiert und bei
Änderungen über mehrere Kanäle veröffentlicht.

Überwacht werden die Messstellen **Magdeburg-Strombrücke**, **Magdeburg-Buckau** und
**Rothensee**. Benachrichtigungen gehen per E-Mail, Bluesky, Mastodon und Twitter/X
hinaus; zusätzlich wird periodisch die aktuelle Ganglinie als Grafik verbreitet.

---

## Bestandteile

| Verzeichnis  | Beschreibung                                                        |
| ------------ | ------------------------------------------------------------------- |
| `bot/`  | Der eigentliche Bot. Läuft stündlich per Cron.                       |
| `logviewer/` | Webbasierter Betrachter für die Logdateien des Bots.                 |

---

## Funktionsweise

Ein Lauf besteht aus drei Phasen:

1. **Messwerte aktualisieren** — Für jede aktive Messstelle werden alle Messwerte seit dem
   zuletzt gespeicherten Zeitpunkt von PEGELONLINE abgerufen und in die Datenbank
   übernommen.

2. **Benachrichtigungen versenden** — Hat sich der Messwert geändert oder liegt die letzte
   Meldung mindestens einen Tag zurück, wird eine Nachricht an alle aktiven Abonnements
   verschickt. Die Nachricht wird aus einer Vorlage erzeugt und enthält neben dem aktuellen
   Wert die Tendenz sowie die Entwicklung über 6 h, 12 h, 24 h, 2 d, 4 d und 7 d.

3. **Ganglinie versenden** — Schwankt der Wasserstand seit der letzten Grafik um mindestens
   50 cm und liegt diese mindestens einen Tag zurück, oder liegt sie mehr als sieben Tage
   zurück, wird die aktuelle Ganglinie verbreitet. Nachts findet kein Versand statt.

Eine ausführliche fachliche Beschreibung steht in [SPEC.md](SPEC.md).

---

## Voraussetzungen

- PHP **ab 8.4**
- MySQL oder MariaDB
- Composer
- Ein Cron-fähiger Host

---

## Installation

```bash
git clone <repository-url> pegelbot
cd pegelbot
composer install --no-dev     # ohne --no-dev, wenn Tests laufen sollen
```

Das Projekt hat **eine** `composer.json` im Wurzelverzeichnis und ein gemeinsames
`vendor/`. Beide Komponenten laden denselben Autoloader. Getrennte Abhängigkeiten je
Komponente waren binnen kurzem auseinandergelaufen, sodass die Tests gegen andere
Fassungen liefen als der Produktivbetrieb.

Die Auflösung ist über `config.platform.php` auf die PHP-Version des Produktivsystems
festgelegt. Damit installiert auch eine neuere lokale PHP-Version keine Pakete, die
auf dem Server nicht laufen.

Konfiguration anlegen:

```bash
cp bot/config/pegelbot-config.sample.php bot/config/pegelbot-config.php
```

Anschließend die Zugangsdaten der Datenbank sowie den gewünschten Loglevel eintragen.

> Die Datei enthält Geheimnisse und wird **nicht** versioniert.

Datenbankschema einspielen:

```bash
mysql -u <benutzer> -p <datenbank> < migrations/000_baseline_schema.sql
```

---

## Betrieb

Der Bot ist ein reines Kommandozeilenprogramm und weist einen Aufruf über den Webserver
ab. Er löst alle Pfade über `__DIR__` auf und ist damit unabhängig vom
Arbeitsverzeichnis.

Manueller Lauf:

```bash
php bot/main.php
```

Regelmäßiger Betrieb per Cron, stündlich zur Minute 5:

```cron
5 * * * * /usr/bin/php /pfad/zu/bot/main.php > /dev/null 2>&1
```

Logdateien liegen in `bot/logs/` und werden von Monolog 14 Tage vorgehalten.

---

## Log-Viewer

Der Log-Viewer zeigt die Logdateien im Browser: Einzelansicht oder mehrere Tage
zusammengefasst, mit Loglevel-Filter, Volltextsuche, automatischer Aktualisierung und
Hell-/Dunkelmodus. Der Zugriff ist durch ein Kennwort geschützt.

```bash
cd logviewer
cp config.sample.php config.php
php bin/hash-password.php
```

Das Skript fragt das Kennwort ab und gibt den Hash aus. Diesen zusammen mit dem
Logverzeichnis des Bots in `config.php` eintragen. Die Datei liegt **oberhalb** von
`public/`, ist damit über HTTP nicht erreichbar und wird nicht versioniert.

Der Zugriff ist durch ein Anmeldeformular geschützt: Kennwort nur als Hash, Sperre
nach fünf Fehlversuchen, CSRF-Schutz, neue Sitzungskennung nach der Anmeldung. Fehlt
ein gültiger Hash, bricht der Betrachter mit HTTP 500 ab, statt die Logs ungeschützt
auszuliefern.

Das Verzeichnis `logviewer/var/auth/` muss für den Webserver beschreibbar sein — dort
liegen die Fehlversuchszähler.

> Der Dokumentenstamm der Subdomain muss auf `logviewer/public/` zeigen — nicht auf
> `logviewer/`. Andernfalls wären `config.php` und die Zähler abrufbar.

---

## Neue Benachrichtigungskanäle

Kanäle werden über die Datenbank registriert. Ein neuer Kanal `beispiel` benötigt:

1. Eine Klasse `PegelBot\beispielController`, abgeleitet von `AboController`.
2. Einen Eintrag `beispiel` in der Tabelle der Kanaltypen.
3. Eine Tabelle `abonnements_beispiel` mit den Zugangsdaten der Abonnements.

Kanäle, die keine Bilder verschicken können, überschreiben `supportsTrend()` mit `false`.

---

## Lokale Entwicklungsumgebung

Für einen echten Botlauf ohne Zugriff auf die Produktivdatenbank:

```bash
mariadb -e "CREATE DATABASE pegelbot_local CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci;
            CREATE USER 'pegelbot_local'@'localhost' IDENTIFIED BY '<kennwort>';
            GRANT ALL PRIVILEGES ON pegelbot_local.* TO 'pegelbot_local'@'localhost';"

mariadb pegelbot_local < migrations/000_baseline_schema.sql
mariadb pegelbot_local < tools/local-demo-data.sql
```

Danach in `bot/config/pegelbot-config.php` auf diese Datenbank zeigen und den Bot
starten.

> Die Demodaten enthalten die drei echten Messstellen, aber **keine Abonnements**.
> Ein Lauf holt damit echte Messwerte von PEGELONLINE und durchläuft alle drei
> Phasen, verschickt aber nichts. Wer Abonnements einträgt, verschickt echte
> Nachrichten.

Das Skript ist wiederholbar und räumt vorher auf.

---

## Tests

```bash
composer install
composer test
```

Die Testsuite liegt in `tests/` und spiegelt die Struktur der Komponenten. Weil alle
Abhängigkeiten aus einem gemeinsamen `vendor/` kommen, laufen die Tests gegen genau
die Bibliotheksfassungen, die auch produktiv arbeiten.

---

## Ausrollen

```bash
cp scripts/deploy.conf.sample scripts/deploy.conf   # einmalig, nicht versioniert
scripts/deploy.sh --dry-run                          # zeigt an, was übertragen würde
scripts/deploy.sh
```

Auf dem Zielserver liegt ein Verzeichnis mit zwei Zielen darin:

| Ziel | Zeigt auf |
| ---- | --------- |
| Cron-Eintrag | `<ziel>/bot/main.php` |
| Dokumentenstamm der Log-Subdomain | `<ziel>/logviewer/public/` |

### Was das Skript tut

Übertragen wird ein Baum, den `git archive` aus dem aktuellen Commit erzeugt.
**Unversioniertes kann damit gar nicht auf den Server gelangen** — weder eine
vergessene Arbeitsdatei noch eine Konfiguration mit Zugangsdaten. Die Frage „ist
alles im Git" beantwortet sich durch die Bauweise, nicht durch eine Zusatzprüfung.

Der Ablauf:

1. Zweig prüfen, sauberes Arbeitsverzeichnis verlangen, auf nicht übertragene
   Commits hinweisen
2. Sicherstellen, dass keine Konfigurationsdatei mit Zugangsdaten im Git-Index oder
   im auszurollenden Commit steht
3. `composer validate`, Testsuite, `composer audit`
4. Auslieferungsbaum aus dem Commit bauen, Abhängigkeiten mit `--no-dev`
   dazupacken — der Server braucht kein Composer und bekommt genau die Fassungen,
   gegen die getestet wurde
5. Erreichbarkeit und PHP-Version des Servers prüfen, Letztere gegen
   `config.platform.php` abgleichen
6. Mit `rsync --delete` übertragen. Ausgenommen und dadurch geschützt: beide
   Konfigurationsdateien, `bot/logs/`, `bot/tmp/`, `logviewer/var/`
7. Laufzeitverzeichnisse anlegen, `logviewer/var/auth` auf 700 setzen, prüfen ob
   die Konfiguration vorhanden ist und `bot/bootstrap.php` durchläuft

Tests, Skripte und `phpunit.xml` bleiben draußen — produktiv wirkt davon nichts.

**Ein vollständiger Botlauf wird nicht ausgelöst**, weil er echte Benachrichtigungen
verschicken würde. Das Skript prüft nur, ob der Bot startet und die Datenbank
erreicht.

### Optionen

| Option | Wirkung |
| ------ | ------- |
| `--dry-run` | Alle Prüfungen, Übertragung nur simuliert |
| `--skip-tests` | Testsuite überspringen |
| `--allow-unpushed` | Trotz nicht übertragener Commits fortfahren |
| `--yes` | Ohne Rückfrage übertragen |

`scripts/deploy.conf` enthält SSH-Ziel und Serverpfad und wird **nicht** versioniert.
Das Skript bricht ab, falls die Datei doch im Git-Index auftaucht.

---

## Mitwirken

Die verbindlichen Entwicklungskonventionen stehen in [CLAUDE.md](CLAUDE.md). Kurzfassung:

- Bezeichner in **Englisch**, Kommentare in **Deutsch**
- `declare(strict_types=1);` in jeder Datei
- Zu jeder Änderung gehören **Unit-Tests**
- Jede Änderung wird zusätzlich per **Smoke-Test** bestätigt
- Ein Commit, ein Anliegen — Commit-Nachrichten auf Deutsch

---

## Datenquelle

Die Messwerte stammen von [PEGELONLINE](https://www.pegelonline.wsv.de/) der
Wasserstraßen- und Schifffahrtsverwaltung des Bundes. Die Daten werden unentgeltlich
bereitgestellt; die Nutzungsbedingungen der WSV sind zu beachten. Es besteht kein
Anspruch auf Richtigkeit oder Verfügbarkeit — die Werte sind für amtliche oder
sicherheitsrelevante Zwecke nicht geeignet.

---

## Lizenz

MIT — siehe [LICENSE](LICENSE).
