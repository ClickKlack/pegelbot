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
cd pegelbot/bot
composer install
```

Konfiguration anlegen:

```bash
cp config/pegelbot-config.sample.php config/pegelbot-config.php
```

Anschließend in `config/pegelbot-config.php` die Zugangsdaten der Datenbank sowie den
gewünschten Loglevel eintragen.

> Die Datei enthält Geheimnisse und wird **nicht** versioniert.

Datenbankschema einspielen:

```bash
mysql -u <benutzer> -p <datenbank> < ../migrations/000_baseline_schema.sql
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
cp public/.htaccess.sample public/.htaccess
htpasswd -c .htpasswd <benutzername>
```

In `config.php` das Logverzeichnis des Bots eintragen, in `public/.htaccess` den
absoluten Pfad zur `.htpasswd`. Beide Dateien liegen **oberhalb** von `public/`
beziehungsweise sind serverspezifisch und werden nicht versioniert.

Die Anmeldung übernimmt der Webserver per HTTP-Basic-Auth. Reicht er keinen
angemeldeten Benutzer durch, bricht der Betrachter mit HTTP 500 ab, statt die Logs
ungeschützt auszuliefern.

> Der Dokumentenstamm der Subdomain muss auf `logviewer/public/` zeigen — nicht auf
> `logviewer/`. Andernfalls wären `config.php` und `.htpasswd` abrufbar.

---

## Neue Benachrichtigungskanäle

Kanäle werden über die Datenbank registriert. Ein neuer Kanal `beispiel` benötigt:

1. Eine Klasse `PegelBot\beispielController`, abgeleitet von `AboController`.
2. Einen Eintrag `beispiel` in der Tabelle der Kanaltypen.
3. Eine Tabelle `abonnements_beispiel` mit den Zugangsdaten der Abonnements.

Kanäle, die keine Bilder verschicken können, überschreiben `supportsTrend()` mit `false`.

---

## Tests

```bash
composer install
composer test
```

Die Testsuite liegt in `tests/`, das Testgerüst ist PHPUnit. Die Entwicklungswerkzeuge
stehen in der `composer.json` im Projektwurzelverzeichnis; die Laufzeit-Abhängigkeiten
des Bots bleiben davon getrennt in `bot/composer.json`, weil beide Komponenten
unabhängig voneinander ausgerollt werden.

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
