# Pegelbot — Spezifikation

> **Status:** Rekonstruiert aus dem bestehenden Quellcode (Stand 13.08.2026, vor der ersten
> Versionierung). Dieses Dokument beschreibt sowohl den **Ist-Zustand** als auch die
> festgestellten Mängel. Es ist die Grundlage für den Umbau zu einem testgesicherten Projekt.
>
> Abschnitte, die auf Vermutung statt auf verifiziertem Code beruhen, sind mit
> **(abgeleitet)** gekennzeichnet.

---

## 1. Zweck und Überblick

**Pegelbot** ist ein cron-gesteuerter Dienst, der Wasserstände von der öffentlichen
PEGELONLINE-REST-API der Wasserstraßen- und Schifffahrtsverwaltung des Bundes (WSV) abruft,
in einer relationalen Datenbank historisiert und bei Änderungen Benachrichtigungen über
mehrere Kanäle verschickt. Zusätzlich verbreitet er periodisch die von der API gerenderte
Ganglinien-Grafik.

Produktiv überwacht werden drei Messstellen an der Elbe im Raum Magdeburg:

| Name                    | UUID                                   |
| ----------------------- | -------------------------------------- |
| `MAGDEBURG-STROMBRÜCKE` | `ccccb57f-a2f9-4183-ae88-5710d3afaefd` |
| `MAGDEBURG-BUCKAU`      | `b8567c1e-8610-4c2b-a240-65e8a74919fa` |
| `ROTHENSEE`             | `e30f2e83-b80b-4b96-8f39-fa60317afcc7` |

### 1.1 Systemkomponenten

Das Projekt besteht aus zwei technisch unabhängigen Anwendungen:

| Komponente  | Verzeichnis  | Art               | Umfang       |
| ----------- | ------------ | ----------------- | ------------ |
| Pegelbot    | `bot/`  | CLI, per Cron     | ~1.100 Zeilen PHP |
| Log-Viewer  | `logviewer/` | Web               | ~800 Zeilen, überwiegend CSS und JavaScript |

Der Log-Viewer besteht aus `public/index.php` (Darstellung und JSON-Endpunkte) und
`src/LogReader.php` (Dateizugriffe, durch Unit-Tests abgedeckt).

Beide teilen sich weder Code noch Konfiguration. Die einzige Kopplung ist das
Log-Verzeichnis, das der Bot beschreibt und der Viewer liest.

### 1.2 Betriebsumgebung

- Shared Hosting, Pfadcontainer `<hosting-home>/public_html/wasserstrassenkreuz.de/`
- Ausführung **stündlich zur Minute :05** (nachgewiesen über 24 `Start`-Einträge pro Logtag)
- Datenbank: MariaDB 10.11.18
- PHP: **8.4.24** in der Produktion, Zielversion ab **8.4** (siehe `CLAUDE.md`)

### 1.3 Ablagetopologie

Bestätigt am 13.08.2026. Das Verzeichnis `public_html/wasserstrassenkreuz.de/` ist bei
diesem Hoster **kein Dokumentenstamm**, sondern lediglich ein Container. Jede Domain und
Subdomain besitzt darunter ihren eigenen Dokumentenstamm.

| Verzeichnis | Über HTTP erreichbar | Anmerkung |
| ----------- | -------------------- | --------- |
| `…/pegel2/`    | **nein** | Der Bot liegt in keinem Dokumentenstamm. |
| `…/pegel_log/` | **ja**, als `pegel-log` | Eigener Dokumentenstamm für den Log-Betrachter. |
| `…/www…/`      | ja  | Dokumentenstamm von `www.wasserstrassenkreuz.de`, für dieses Projekt ohne Belang. |

Daraus folgt:

- `main.php` lässt sich **nicht** über HTTP auslösen. Eine SAPI-Wache im Einstiegspunkt
  ist trotzdem sinnvoll, damit eine spätere Umkonfiguration des Hosters diese Zusicherung
  nicht stillschweigend aufhebt.
- Weder `pegelbot-config.php` noch `logs/` sind über HTTP abrufbar. Die
  Zugangsdaten und die in den Logs enthaltenen Abonnenten-Adressen sind
  **nicht** exponiert.
- **Der Log-Betrachter ist die einzige über HTTP erreichbare Komponente** und damit die
  gesamte Angriffsfläche des Projekts. Er gibt Logs aus, die E-Mail-Adressen von
  Abonnenten enthalten; sein Kennwortschutz ist die einzige Schranke davor. Das gewichtet
  die Befunde S2, S4 und S5 entsprechend hoch.

---

## 2. Externe Schnittstelle: PEGELONLINE

**Basis-URL:** `https://www.pegelonline.wsv.de/webservices/rest-api/v2/`

### 2.1 Messwerte (JSON)

```
GET stations/{uuid}/W/measurements.json?start={ISO8601}[&end={ISO8601}]
Accept-Encoding: gzip
```

Antwort: JSON-Array von Objekten mit den Feldern `timestamp` (ISO 8601 mit Zeitzone) und
`value` (Zahlenwert, Einheit cm).

Verarbeitung: jedes Element wird zu einem `Measurement` mit nach UTC normalisiertem
Zeitstempel. Ein `Content-Type`, der nicht `application/json` enthält, führt zu einem
Fehlerlog und einem leeren Ergebnis-Array.

### 2.2 Ganglinie (PNG)

```
GET stations/{uuid}/W/measurements.png?start=P{days}D&width={width}&height={height}
Accept-Encoding: gzip
```

Aufgerufen mit den Vorgabewerten `days=14`, `width=600`, `height=400`. Ein Antwort-
`Content-Type` ohne `image/png` löst eine Exception aus.

---

## 3. Fachlicher Ablauf

Ein Lauf besteht aus drei streng nacheinander ausgeführten Phasen. Der Einstiegspunkt lädt
die Konfiguration, initialisiert Logger und Datenbankverbindung, prüft die Verbindung und
delegiert an die Steuerklasse.

### Phase 1 — Messwerte aktualisieren

Für jede Messstelle mit `update_active = 1`:

1. Letzten gespeicherten Zeitpunkt aus der Messwerttabelle ermitteln.
   Existiert kein Messwert, wird als Startpunkt **jetzt minus 24 Stunden** verwendet.
2. Messwerte ab **letzterZeitpunkt + 1 Sekunde** von der API abrufen.
3. Jeden Messwert einzeln in die Datenbank einfügen.

Zeitstempel werden intern durchgängig in **UTC** gehalten und ausschließlich für Ausgabe und
Template-Ersetzung nach `Europe/Berlin` konvertiert.

### Phase 2 — Benachrichtigungen versenden

Eine einzelne SQL-Abfrage liefert je Messstelle mit Abo-Zuordnung:

- aktuellster Messwert und dessen Zeitpunkt
- Messwert zum Zeitpunkt der letzten Benachrichtigung
- **sechs Differenzwerte** gegenüber −6 h, −12 h, −24 h, −2 d, −4 d, −7 d

Die Differenzen werden über exakte Zeitpunktvergleiche (`DATE_ADD(..., INTERVAL -n ...)`)
gebildet. Existiert zum errechneten Vergleichszeitpunkt kein Messwert, liefert die Abfrage
den Literalwert `N/A`.

**Auslöseregel:**

```
letzter_messwert IST NICHT NULL UND letzter_messwert <> messwert_aktuell
ODER
Abstand zwischen letzter Benachrichtigung und aktuellem Messzeitpunkt >= 1 Tag
```

Nach dem Versand wird der Zeitpunkt der letzten Benachrichtigung auf den aktuellen
Messzeitpunkt gesetzt.

### Phase 3 — Ganglinie versenden

Ermittelt je Messstelle das Minimum und Maximum aller Messwerte seit der letzten
Ganglinien-Versendung.

**Nachtsperre:** Läuft die Prüfung zu einer Stunde `< 6` oder `> 22`, wird ohne Versand
abgebrochen.

**Auslöseregel:**

```
( |min_messwert - max_messwert| >= 50 UND letzte Ganglinie >= 1 Tag her )
ODER
letzte Ganglinie >= 7 Tage her
```

Die Grafik wird einmal pro Messstelle abgerufen und an alle Kanäle verteilt, die
Ganglinien unterstützen. Ein leeres Ergebnis führt zum stillen Abbruch ohne Versand.

---

## 4. Versand-Architektur

Der Versand ist datengetrieben und über Namenskonvention aufgelöst:

1. Aus der Tabelle der Kanaltypen werden alle aktiven Kanalnamen gelesen.
2. Pro Name wird die Klasse `PegelBot\{name}Controller` per `class_exists()` geprüft und
   instanziiert. Fehlt sie, wird eine Exception geworfen.
3. Aus der Tabelle `abonnements_{name}` werden alle Abonnements der Messstelle mit
   `aktiv = 1` geladen.
4. Je Abonnement wird `postNotify()` bzw. `postTrend()` aufgerufen.

Fehler werden **pro Abonnement** gefangen und protokolliert. Ein ausgefallener Kanal
blockiert die übrigen nicht.

### 4.1 Kanal-Vertrag

```php
interface AboInterface {
    public function postNotify(array $abo_details, string $message_content);
    public function postTrend(array $abo_details, string $message_content, string $image);
    public function supportsTrend(): bool;
}
```

Die abstrakte Basisklasse nimmt den Logger entgegen und liefert `supportsTrend()` mit
Vorgabewert `true`.

### 4.2 Implementierte Kanäle

| Kanal      | Bibliothek / Technik            | Benötigte Abo-Felder                                                        |
| ---------- | ------------------------------- | --------------------------------------------------------------------------- |
| `mail`     | PHPMailer                       | `email`                                                                     |
| `bluesky`  | `cjrasmussen/bluesky-api`       | `handle`, `passwort`                                                        |
| `mastodon` | cURL direkt gegen die REST-API  | `server`, `status_api`, `access_token`, `beschreibung`                      |
| `twitter`  | `abraham/twitteroauth`          | `consumer_key`, `consumer_secret`, `oauth_access_token`, `oauth_access_token_secret`, `beschreibung` |

Besonderheiten:

- **Mail:** Absenderadresse ist im Quelltext fest verdrahtet. Die Ganglinie wird als
  Dateianhang `Ganglinie.png` angehängt.
- **Bluesky:** Bild-Upload über `com.atproto.repo.uploadBlob`, Post über
  `com.atproto.repo.createRecord`, Sprache fest `de`.
- **Mastodon:** Bild wird über eine Zwischendatei hochgeladen, Sichtbarkeit fest `unlisted`,
  Sprache fest `de`. Akzeptierte HTTP-Codes beim Upload: 200 und 202.
- **Twitter:** Medien-Upload über API v1.1, Tweet über API v2. Erfolgskriterium HTTP 201.

---

## 5. Nachrichten-Templates

Templates liegen in der Datenbank (ein Text für Benachrichtigungen, einer für Ganglinien)
und werden per einfacher Textersetzung befüllt.

### 5.1 Platzhalter der Benachrichtigung

| Platzhalter        | Inhalt                                                     |
| ------------------ | ---------------------------------------------------------- |
| `{MESSPUNKT}`      | Name der Messstelle                                        |
| `{MESSWERT}`       | aktueller Messwert                                         |
| `{DATE}`           | Datum des Messwerts, Format `dd.mm.YYYY`, Europe/Berlin     |
| `{TIME}`           | Uhrzeit des Messwerts, Format `HH:MM`, Europe/Berlin        |
| `{TENDENZ}`        | `Tendenz steigend` / `Tendenz fallend` / `Tendenz gleich`; leer, wenn kein Vorwert existiert |
| `{ENTWICKLUNG_6h}` | Differenz zu vor 6 Stunden                                 |
| `{ENTWICKLUNG_12h}`| Differenz zu vor 12 Stunden                                |
| `{ENTWICKLUNG_24h}`| Differenz zu vor 24 Stunden                                |
| `{ENTWICKLUNG_2d}` | Differenz zu vor 2 Tagen                                   |
| `{ENTWICKLUNG_4d}` | Differenz zu vor 4 Tagen                                   |
| `{ENTWICKLUNG_7d}` | Differenz zu vor 7 Tagen                                   |

**Vorzeichenformatierung der Entwicklungswerte:**

| Eingabe          | Ausgabe   |
| ---------------- | --------- |
| nicht numerisch  | unverändert (also `N/A`) |
| negativ          | unverändert (Minuszeichen bereits enthalten) |
| exakt 0          | `+/-0`    |
| positiv          | `+` vorangestellt |

### 5.2 Platzhalter der Ganglinie

Nur `{MESSPUNKT}`.

---

## 6. Datenmodell

Produktivsystem: **MariaDB 10.11.18**, betrieben unter **PHP 8.4.24**.

Das vollständige Schema liegt seit dem 13.08.2026 vor und ist originalgetreu als
[`migrations/000_baseline_schema.sql`](migrations/000_baseline_schema.sql) abgelegt.
**Dieser Abschnitt ist vollständig verifiziert** — er enthält keine Vermutungen mehr.

Alle Tabellen: `InnoDB`. Zeichensatz `utf8mb3_general_ci`, mit einer Ausnahme
(`abonnements_mastodon`, siehe 6.4).

### 6.1 Kerntabellen

#### `messstellen` — Stammdaten der überwachten Pegel

| Spalte          | Typ                | Eigenschaften                       |
| --------------- | ------------------ | ----------------------------------- |
| `id`            | `int(10) unsigned` | Primärschlüssel, `AUTO_INCREMENT`   |
| `name`          | `varchar(100)`     | `NOT NULL`, **eindeutig** (`name_uq`)   |
| `nummer`        | `int(10) unsigned` | `NOT NULL`, **eindeutig** (`nummer_uq`) |
| `uuid`          | `varchar(50)`      | `NOT NULL`, **kein Index**          |
| `update_active` | `tinyint(1)`       | `NOT NULL`, Vorgabe `1`             |

#### `messwerte` — Messwerthistorie

| Spalte           | Typ                | Eigenschaften                                  |
| ---------------- | ------------------ | ---------------------------------------------- |
| `messstellen_id` | `int(10) unsigned` | Teil des Primärschlüssels, Fremdschlüssel, **fälschlich `AUTO_INCREMENT`** |
| `zeitpunkt`      | `datetime`         | Teil des Primärschlüssels, UTC                  |
| `messwert`       | `smallint(6)`      | `NOT NULL`, vorzeichenbehaftet, Einheit cm      |
| `last_update`    | `timestamp`        | `NOT NULL`, Vorgabe `current_timestamp()`, vom Code nicht genutzt |

- Primärschlüssel (`messstellen_id`, `zeitpunkt`) — **verhindert Doppeleinträge**
- Index `zeitpunkt`
- Fremdschlüssel `messstellen_id_ctr` → `messstellen`.`id`

Wertebereich `smallint` vorzeichenbehaftet: −32.768 bis 32.767 cm. Für Elbepegel
weit ausreichend, negative Werte sind darstellbar.

#### `messstelllen_abo_zuordnung` — Vorlagen und Versandzeitpunkte

| Spalte                      | Typ             | Eigenschaften                           |
| --------------------------- | --------------- | --------------------------------------- |
| `messstellen_id`            | `int(10) unsigned` | **Primärschlüssel**, Fremdschlüssel   |
| `letzter_zeitpunkt`         | `datetime`      | **`NOT NULL`**                          |
| `letzter_verlaufszeitpunkt` | `datetime`      | **`NOT NULL`**                          |
| `message_template`          | `varchar(2048)` | **`NOT NULL`**                          |
| `trend_template`            | `varchar(2048)` | **`NULL` erlaubt** — siehe B11          |
| `last_update`               | `timestamp`     | `ON UPDATE current_timestamp()`, vom Code nicht genutzt |

Der Primärschlüssel auf `messstellen_id` erzwingt **genau einen** Zuordnungsdatensatz
je Messstelle. Es gibt also je Pegel genau eine Benachrichtigungs- und eine
Ganglinienvorlage.

#### `abo_types` — Registrierte Versandkanäle

| Spalte | Typ                                     | Eigenschaften    |
| ------ | --------------------------------------- | ---------------- |
| `name` | `varchar(15)`, `utf8mb3_german2_ci`     | Primärschlüssel  |

Inhalt: `mail`, `bluesky`, `mastodon`, `twitter`. Aus diesem Wert werden zur Laufzeit
Klassen- **und** Tabellennamen zusammengesetzt (siehe S7).

### 6.2 Abonnement-Tabellen

Alle vier folgen demselben Muster: `AUTO_INCREMENT`-Primärschlüssel `{kanal}_abo_id`,
Fremdschlüssel `messstellen_id` → `messstellen`.`id`, je ein Index auf `messstellen_id`
und `aktiv`, `beschreibung varchar(2048) NULL`, `aktiv int(1) unsigned NOT NULL DEFAULT 1`.

| Tabelle                | Kanalspezifische Spalten (alle `varchar(255) NOT NULL`)                                      |
| ---------------------- | -------------------------------------------------------------------------------------------- |
| `abonnements_mail`     | `email`                                                                                      |
| `abonnements_bluesky`  | `handle`, `passwort`                                                                         |
| `abonnements_mastodon` | `server`, `status_api`, `access_token`                                                       |
| `abonnements_twitter`  | `oauth_access_token`, `oauth_access_token_secret`, `consumer_key`, `consumer_secret`         |

Bemerkenswert: Die Twitter-Zugangsdaten enthalten `consumer_key` und `consumer_secret`
**je Abonnement** statt anwendungsweit — jedes Abonnement bringt seine eigene
Anwendungsregistrierung mit.

### 6.3 Referenzielle Integrität

Alle sechs Fremdschlüssel verweisen auf `messstellen`.`id`. Keiner besitzt eine
`ON DELETE`- oder `ON UPDATE`-Klausel, es gilt also durchgängig `RESTRICT`: Eine
Messstelle kann erst gelöscht werden, wenn alle Messwerte, die Zuordnung und sämtliche
Abonnements entfernt sind.

### 6.4 Bekannte Schema-Altlasten

| Nr. | Schwere | Beschreibung |
| --- | ------- | ------------ |
| D1 | **hoch** | **`messwerte`.`messstellen_id` ist `AUTO_INCREMENT`.** Die Spalte ist ein Fremdschlüssel und Teil des zusammengesetzten Primärschlüssels — ein automatischer Zähler ist dort fachlich falsch. Solange der Code den Wert stets ausdrücklich setzt, fällt es nicht auf; ein Einfügevorgang ohne Angabe erzeugt jedoch eine erfundene Messstellennummer und scheitert erst am Fremdschlüssel. |
| D2 | mittel | **`abonnements_mastodon` ist `latin1` / `latin1_swedish_ci`**, alle anderen Tabellen sind `utf8mb3`. Umlaute in `beschreibung` werden dadurch fehlerhaft gespeichert oder gelesen. |
| D3 | mittel | Durchgängig `utf8mb3` statt `utf8mb4`. In MariaDB veraltet; Zeichen außerhalb der Basic Multilingual Plane — insbesondere Emojis in Nachrichtenvorlagen — lassen sich nicht speichern. |
| D4 | mittel | **`messstellen`.`uuid` hat weder Index noch Eindeutigkeitsbedingung**, obwohl es der fachliche Schlüssel zur PEGELONLINE-Schnittstelle ist. Zwei Messstellen könnten dieselbe UUID führen. |
| D5 | niedrig | Der Tabellenname `messstelllen_abo_zuordnung` enthält einen Tippfehler (drei „l"). |
| D6 | niedrig | `aktiv` ist als `int(1) unsigned` statt `tinyint(1)` modelliert, `update_active` dagegen als `tinyint(1)` — uneinheitlich. Der Code prüft ausschließlich `aktiv = 1`; jeder andere Wert wirkt wie „inaktiv". |
| D7 | niedrig | Der Indexname `abo_messstellen_id_fk3` wird in `abonnements_bluesky` **und** `abonnements_mastodon` verwendet. Zulässig, da Indexnamen tabellenlokal sind, aber ein Kopierfehler. |
| D8 | niedrig | Die Spalten `last_update` in `messwerte` und `messstelllen_abo_zuordnung` werden vom Code nicht ausgewertet. |
| D9 | niedrig | `messstellen`.`nummer` ist eindeutig und `NOT NULL`, wird von der Anwendung aber nirgends verwendet — sie wird nur in das Objekt geladen. |
| D10 | — | Alle Bezeichner sind deutsch; die Zielkonvention ist Englisch (siehe `CLAUDE.md`). |

**Zugangsdaten liegen im Klartext** in allen vier Abonnement-Tabellen — siehe S8.

---

## 7. Konfiguration

### 7.1 Pegelbot

Konfiguration über `define()`-Konstanten in `bot/config/pegelbot-config.php`. Eine Vorlage ohne
Geheimnisse liegt als `pegelbot-config.sample.php` bei.

| Konstante     | Bedeutung                                     |
| ------------- | --------------------------------------------- |
| `DB_DRIVER`   | Doctrine-DBAL-Treiber                          |
| `DB_NAME`     | Datenbankname                                  |
| `DB_USER`     | Benutzername                                   |
| `DB_PASSWORD` | Kennwort                                       |
| `DB_HOST`     | Hostname                                       |
| `DEBUG_LEVEL` | Monolog-Loglevel                               |

### 7.2 Logging

Monolog-Kanal `pegelbot`, `RotatingFileHandler` mit 14 Tagen Vorhaltezeit, Ziel
`bot/logs/pegelbot.log`. Die Vorhaltezeit regelt allein Monolog; ein früher zusätzlich
vorhandenes Aufräumskript war überflüssig und ist entfallen.

Die Anwendung schreibt **parallel** über den Logger und über `echo` auf die Standardausgabe —
beide Ausgaben sind inhaltlich weitgehend redundant.

### 7.3 Log-Viewer

Konfiguration in `logviewer/config.php`, die ein Feld mit denselben Werten zurückgibt:
Logverzeichnis, Dateinamenpräfix, Zugriffskennwort, maximale Zeilenzahl,
Aktualisierungsintervall, Zeitzone. Vorlage: `config.sample.php`.

Die Datei liegt **oberhalb** des Dokumentenstamms `logviewer/public/` und ist damit über
HTTP nicht erreichbar. Fehlt sie, antwortet der Betrachter mit HTTP 500 und einem Hinweis
statt mit einem Fehler des PHP-Interpreters.

Der Viewer bietet drei JSON-Endpunkte (`list`, `file`, `combined`) und eine Oberfläche
mit Hell-/Dunkelmodus, Loglevel-Filter, Volltextsuche und automatischer Aktualisierung.

**Zugriffsschutz:** Anmeldeformular mit Sitzung. Die sicherheitsrelevante Logik liegt
in `LogViewer\Authenticator` und ist durch Unit-Tests abgedeckt; `index.php` enthält
nur Sitzung, Formular und CSRF-Prüfung.

| Eigenschaft | Umsetzung |
| ----------- | --------- |
| Kennwortablage | `password_hash()`-Hash in `config.php`, erzeugt über `bin/hash-password.php`. Kein Klartext. |
| Vergleich | `password_verify()`, laufzeitkonstant |
| Versuchsbegrenzung | 5 Fehlversuche, danach 15 Minuten Sperre. Zähler **auf der Platte** je Aufrufer, nicht in der Sitzung — sonst genügte das Verwerfen des Sitzungsplätzchens zur Umgehung. |
| Aufrufer-Kennung | SHA-256 über IP-Adresse und Browserkennung; im Zustandsverzeichnis stehen keine IP-Adressen im Klartext |
| Sitzungsübernahme | `session_regenerate_id(true)` nach erfolgreicher Anmeldung |
| CSRF | Zufallstoken in der Sitzung, Abgleich mit `hash_equals()` bei Anmeldung **und** Abmeldung |
| Sitzungsplätzchen | `httponly`, `samesite=Strict`, `secure` bei HTTPS |
| Ablauf | Anmeldung per POST, danach Umleitung (POST-Redirect-GET) |

Ohne gültigen Hash in `config.php` bricht der Betrachter mit HTTP 500 ab, statt
Logdateien ungeschützt auszuliefern. Der Fall „unfertig eingerichtet" ist damit
sichtbar kaputt statt unbemerkt offen.

Die Fehlversuchszähler liegen in `logviewer/var/auth/`, oberhalb des
Dokumentenstamms und nicht versioniert. `logviewer/public/.htaccess` schaltet
zusätzlich die Verzeichnisauflistung ab und lässt außer `index.php` nichts ausliefern.

---

## 8. Abhängigkeiten

Das Projekt hat **eine** `composer.json` im Wurzelverzeichnis und ein gemeinsames
`vendor/`. Beide Komponenten laden denselben Autoloader.

| Paket                     | Version | Zweck                         |
| ------------------------- | ------- | ----------------------------- |
| `doctrine/dbal`           | 3.10.6  | Datenbankzugriff              |
| `monolog/monolog`         | 3.10.0  | Logging                       |
| `guzzlehttp/guzzle`       | 7.15.3  | HTTP-Client für PEGELONLINE   |
| `phpmailer/phpmailer`     | 6.12.0  | E-Mail-Versand                |
| `cjrasmussen/bluesky-api` | 1.1.2   | Bluesky                       |
| `abraham/twitteroauth`    | 6.2.0   | Twitter/X                     |
| `psr/log`                 | 3.0.2   | Protokoll-Schnittstelle       |

Entwicklung: `phpunit/phpunit` 11.5.

`logviewer/` kommt ohne Abhängigkeiten aus und lädt seine beiden Klassen unmittelbar,
damit es auch ohne Autoloader lauffähig bleibt.

Die Auflösung ist über `config.platform.php` auf **8.4.24** festgelegt, die
PHP-Version des Produktivsystems. Andernfalls löst Composer gegen die örtlich
vorhandene Fassung auf und kann Pakete einspielen, die auf dem Server nicht laufen.

### 8.1 Warum nur eine Abhängigkeitsdatei

Zwischenzeitlich existierten zwei: eine im Wurzelverzeichnis für die Werkzeuge, eine
in `bot/` für die Laufzeit. Binnen weniger Stunden liefen **sechs von zehn**
gemeinsamen Paketen auseinander — Guzzle stand bei 7.15.3 gegenüber 7.8.1. Die Tests
prüften damit anderes Verhalten als das produktiv wirksame, was den Zweck der
Testabdeckung untergräbt. Getrennte Abhängigkeiten kommen für dieses Projekt daher
nicht mehr in Frage.

---

## 9. Festgestellte Mängel

### 9.1 Fehler im Code

| Nr. | Schwere | Fundstelle | Beschreibung |
| --- | ------- | ---------- | ------------ |
| ~~B1~~ | **behoben** 13.08.2026 | `src/wsa/PegelOnlineApi.php` | `catch (ServerException $e)` ohne passenden `use`-Import: Der Name löste im Namensraum `WSA` auf und traf nie zu, jede 5xx-Antwort beendete den kompletten Lauf. Beide Methoden fangen die Ausnahme jetzt, protokollieren sie und liefern ein leeres Ergebnis; der Lauf verarbeitet die übrigen Messstellen weiter. Beim nächsten Lauf wird ab dem zuletzt gespeicherten Zeitpunkt nachgeholt. Durch fünf Tests abgedeckt. |
| B2 | **hoch** | `src/MessstellenController.php:228` | Der Zeitpunkt der letzten Benachrichtigung wird auch dann fortgeschrieben, wenn **alle** Versandversuche fehlgeschlagen sind. Eine an einem Kanalausfall gescheiterte Benachrichtigung ist dauerhaft verloren. |
| B3 | mittel | `src/MessstellenController.php:255` | Die Nachtsperre vergleicht die **UTC**-Stunde gegen die als Ortszeit gedachten Grenzen 6 und 22. Effektiv sperrt sie in der Sommerzeit von 00 bis 08 Uhr Ortszeit. |
| B4 | mittel | `src/wsa/WSAServices.php:59` | Das Ergebnis von `json_decode()` wird nicht geprüft. Bei ungültigem JSON iteriert die Schleife über `null`. |
| ~~B5~~ | **entkräftet** | — | Vermutet wurde ein möglicher `NULL`-Wert bei `letzter_zeitpunkt`. Das Schema weist die Spalte als `datetime NOT NULL` aus; der Fall kann nicht eintreten. |
| B6 | mittel | `src/MessstellenController.php:64` | Einfügen der Messwerte ohne Transaktion. Der Primärschlüssel (`messstellen_id`, `zeitpunkt`) schließt Doppeleinträge zwar aus, wandelt sie aber in eine **Verletzung der Eindeutigkeitsbedingung** um. Diese wird nicht gefangen und beendet den Lauf; die zuvor eingefügten Werte bleiben als Teilzustand zurück. Auslöser: überlappende Läufe oder wiederholte Zeitstempel in einer API-Antwort. |
| B7 | niedrig | `src/MessstellenController.php:291` | Prüfung auf `null` bei einer Methode mit Rückgabetyp `string` — toter Code. |
| B8 | niedrig | `src/mastodonController.php:51`, `src/twitterController.php:53` | Beide Kanäle schreiben in dieselbe feste Zwischendatei `tmp/Ganglinie.png`. Bei überlappenden Läufen entsteht ein Wettlauf. |
| ~~B9~~ | **behoben** 13.08.2026 | `src/wsa/Measurement.php` | Bestätigt: PEGELONLINE liefert die Werte **immer** als Gleitkommazahl (`38.0`), in 576 geprüften Werten zweier Messstellen keiner mit Nachkommaanteil. Die früheren Fassungen liefen ohne strikte Typen und wandelten still um. `Measurement` nimmt jetzt `int\|float` entgegen und rundet ausdrücklich, statt abzuschneiden. |
| B10 | niedrig | `bootstrap.php:36` | Der Verbindungstest `SELECT 1 FROM dual` ist MySQL-spezifisch. |
| B13 | niedrig | `src/wsa/PegelOnlineApi.php` | Der Abfrageteil wird als fertige Zeichenkette übergeben, der Zeitzonenversatz `+00:00` also unkodiert übertragen. In einem Abfrageteil steht `+` für ein Leerzeichen, korrekt wäre `%2B`. PEGELONLINE akzeptiert es seit jeher; ein Test hält das Verhalten fest. Zu beheben, sobald die Abfrage über ein Feld statt über eine Zeichenkette gebaut wird. |
| B11 | **hoch** | `src/MessstellenController.php:172` | `trend_template` ist `NULL` erlaubt, `GetTrendMessage()` übergibt den Wert ungeprüft an `str_replace()`. Unter PHP 8.4 ist `null` als Betreff veraltet; die erzeugte Nachricht ist leer. Das ist **kein theoretischer Fall**: Das Migrationsskript setzt die Vorlage nur für die Messstellen 1 und 3 — die dritte Messstelle hat keine. Sie postet Ganglinien ohne Text. |
| B12 | mittel | `src/MessstellenController.php:344` | Phase 3 ermittelt `zeitpunkt_aktuell` über eine Unterabfrage ohne Verbund auf `messwerte`. Für eine Messstelle **ohne jeden Messwert** ist der Wert `NULL` und wird in die Spalte `letzter_verlaufszeitpunkt` geschrieben, die `NOT NULL` ist — die Anweisung scheitert. Phase 2 ist davon nicht betroffen, weil sie einen inneren Verbund verwendet. Betrifft neu angelegte Messstellen. |

### 9.2 Sicherheit

| Nr. | Schwere | Beschreibung |
| --- | ------- | ------------ |
| S1 | **kritisch** | `bot/config/pegelbot-config.php` enthält echte Datenbank-Zugangsdaten und darf niemals versioniert werden. |
| ~~S2~~ | **behoben** 13.08.2026 | Das Zugriffskennwort stand im Klartext im Quelltext. Es liegt jetzt als `password_hash()`-Hash in der nicht versionierten `config.php`. Das alte Kennwort gilt als kompromittiert und wird nicht weiterverwendet. |
| ~~S3~~ | **behoben** 13.08.2026 | Absolute Serverpfade sind aus allen versionierten Dateien verschwunden: Der Log-Betrachter liest sie aus der Konfiguration, `deletelog.sh` ist entfallen. Verbleibt nur `bot/.htaccess`, siehe O11. |
| ~~S4~~ | **behoben** 13.08.2026 | Fehlende Begrenzung der Anmeldeversuche, kein CSRF-Schutz, keine Sitzungserneuerung — alle drei jetzt umgesetzt und getestet, siehe Abschnitt 7.3. |
| ~~S5~~ | **behoben** 13.08.2026 | Der Vergleich läuft über `password_verify()` und ist damit laufzeitkonstant. |
| S6 | niedrig | E-Mail-Adressen von Abonnenten werden im Klartext protokolliert. |
| S7 | niedrig | Tabellen- und Klassennamen werden aus Datenbankinhalten zusammengesetzt. Der Inhalt ist zwar selbst verwaltet, das Muster bleibt aber angreifbar. |
| ~~S9~~ | **entschärft** 13.08.2026 | Das Dokument beschrieb ungeschlossene Schwachstellen eines laufenden Systems und war damit vor einer Veröffentlichung selbst ein Risiko. Mit der Behebung von S2, S4 und S5 beschreibt es an dieser Stelle nur noch geschlossene Befunde. Vor der Übertragung ist zu prüfen, ob neu hinzugekommene offene Befunde denselben Vorbehalt auslösen. |
| ~~S10~~ | **behoben** 13.08.2026 | 13 bekannte Schwachstellen in `guzzlehttp/guzzle` 7.8.1 und `guzzlehttp/psr7` 2.6.2. Mit der Zusammenführung der Abhängigkeiten aktualisiert; `composer audit` meldet nichts mehr. Nebeneffekt: Unter PHP 8.5 traten Deprecation-Meldungen aus den alten Bibliotheken auf, die damit ebenfalls entfallen. |
| S8 | **hoch** | Alle Kanal-Zugangsdaten liegen **unverschlüsselt** in der Datenbank: OAuth-Schlüssel und -Geheimnisse (Twitter), Anwendungskennwörter (Bluesky), Zugriffsmarken (Mastodon). Ein Datenbankauszug — etwa der zur Fehlersuche erstellte — gibt damit sämtliche Konten preis. Auszüge dieser Tabellen dürfen niemals versioniert oder weitergegeben werden. |

Positiv anzumerken: Die Pfadprüfung der Viewer-Endpunkte (`basename()` in Kombination mit
einem strengen regulären Ausdruck) ist korrekt umgesetzt.

### 9.3 Struktur

- Kein Git, keine Lizenz, kein README, kein Änderungsprotokoll — **wird mit diesem Schritt behoben**
- Keine Tests, kein PHPUnit, keine Entwicklungsabhängigkeiten
- **Kein vollständiges Datenbankschema** — die Datenbank ist aus dem Projekt heraus nicht
  reproduzierbar. Das ist der härteste Blocker für Integrationstests.
- Keine kontinuierliche Integration, keine statische Analyse
- Im Arbeitsverzeichnis liegen Artefakte, die nicht versioniert gehören:
  `bot/vendor/` (6,1 MB), `bot/logs/` (1,7 MB), `bot/tmp/`, `.DS_Store`
- `declare(strict_types=1)` nur in `bootstrap.php`, nicht in den Klassen
- Objekte werden unnötig per Referenz (`&$connection`) übergeben

---

## 10. Testbarkeit

Der Code ist im gegenwärtigen Zustand **nicht sinnvoll unit-testbar**. Ursachen:

| Nr. | Hindernis | Auswirkung |
| --- | --------- | ---------- |
| ~~T1~~ | **behoben** 13.08.2026 — `WSA\MeasurementApiInterface` mit der Umsetzung `WSA\PegelOnlineApi`. HTTP-Client und Protokoll werden hereingereicht, die Klasse ist über den Konstruktor einschleusbar. Die statische Klasse `WSAServices` ist entfallen. | — |
| T2 | Kanal-Controller werden per `new $class(...)` aus einem Datenbankwert erzeugt | Keine Einschleusung von Testdoubles möglich |
| T3 | `new \DateTime("now")` steht direkt in der Fachlogik | Nachtsperre und alle Zeitschwellen sind nicht deterministisch prüfbar |
| T4 | `echo` ist mit der Fachlogik vermischt | Ausgabe nicht abtrennbar, Ergebnisse nicht prüfbar |
| T5 | Konfiguration über globale `define()`-Konstanten | Pro Prozess nur einmal setzbar |
| T6 | Kein vollständiges Schema | Keine Testdatenbank aufbaubar |

### 10.1 Bereits testbar

Die Erzeugung der Nachrichtentexte und die Vorzeichenformatierung der Entwicklungswerte sind
**reine Funktionen** über dem Abo-Datensatz. Sie sind ohne jedes Refactoring prüfbar, sobald
die Klasse instanziierbar ist, und decken zugleich das für Abonnenten sichtbarste Verhalten
ab. **Das ist der vorgesehene erste Testfall.**

---

## 11. Umbauplan

### Stufe 0 — Absicherung vor der ersten Versionierung

**Abgeschlossen am 13.08.2026**, bis auf die Übertragung nach Codeberg.

- ~~Struktur: `pegelhub/` → `bot/`, `pegel-log/` → `logviewer/public/`~~
- ~~`.gitignore` für `vendor/`, `logs/`, `tmp/`, Konfigurationsdateien, `.DS_Store`~~
- ~~Geheimnisse auslagern (S1, S2)~~ — Viewer-Kennwort muss noch **gewechselt** werden
- ~~Serverpfade in Konfiguration überführen (S3)~~
- ~~Lizenz, README, SPEC, Entwicklungskonventionen anlegen~~
- ~~SAPI-Wache im Einstiegspunkt~~
- ~~`git init` und erste Übernahme~~
- GitHub als Gegenstelle einrichten und übertragen — **teilweise**, Gegenstelle
  `origin` zeigt auf `https://github.com/ClickKlack/pegelbot`, die Übertragung steht aus
  (siehe S9)

### Stufe 1 — Reproduzierbarkeit

- ~~Vollständiges Ausgangsschema aus der Produktivdatenbank ziehen~~ — **erledigt**,
  `migrations/000_baseline_schema.sql`
- Migrationswerkzeug festlegen und Versionstabelle einführen
- Migration: `AUTO_INCREMENT` auf `messwerte`.`messstellen_id` entfernen (D1)
- Migration: `abonnements_mastodon` von `latin1` auf `utf8mb4` (D2)
- Migration: Gesamtschema von `utf8mb3` auf `utf8mb4` (D3)
- Migration: Index bzw. Eindeutigkeitsbedingung auf `messstellen`.`uuid` (D4)

### Stufe 2 — Testgerüst

- PHPUnit als Entwicklungsabhängigkeit, `autoload-dev`, `phpunit.xml`
- Erste Tests gegen die reinen Template-Funktionen (Abschnitt 10.1)

### Stufe 3 — Gezielte Refactorings

Je ein Commit pro Schritt, jeweils testbegleitet:

1. B1 beheben, mit Regressionstest
2. API-Zugriff hinter ein einschleusbares Interface legen (T1)
3. Zeitquelle abstrahieren (T3), damit B3 prüfbar wird
4. Kanal-Registrierung statt dynamischer Klassennamen (T2, S7)
5. `echo` durch Logger bzw. eine Ausgabeschicht ersetzen (T4)
6. B2 beheben: Zeitpunkt nur bei mindestens einem erfolgreichen Versand fortschreiben
7. `declare(strict_types=1)` flächendeckend, B9 klären
8. Bezeichner schrittweise auf Englisch umstellen (siehe `CLAUDE.md`)

### Stufe 4 — Automatisierung

- GitHub Actions: Lint, PHPUnit, PHPStan (über `shivammathur/setup-php`)
- Einstiegspunkt nach `bot/bin/pegelbot` verschieben; der Cron-Eintrag wird in diesem
  Schritt ohnehin angefasst
- Ausrollen per `git pull` über SSH

---

## 12. Offene Punkte

| Nr. | Frage |
| --- | ----- |
| ~~O1~~ | **erledigt** 13.08.2026 — vollständiges Schema liegt vor, siehe Abschnitt 6 und `migrations/000_baseline_schema.sql`. |
| O6 | Sollen die Kanal-Zugangsdaten verschlüsselt in der Datenbank abgelegt oder in die Konfigurationsdatei verlagert werden? (betrifft S8) |
| O7 | Umstellung von `utf8mb3` auf `utf8mb4` sowie `abonnements_mastodon` von `latin1` — als eigene Migration in Stufe 1? (betrifft D2, D3) |
| O8 | Fehlt der dritten Messstelle tatsächlich die Ganglinien-Vorlage, oder wurde sie nachträglich gesetzt? (betrifft B11 — bitte `SELECT messstellen_id, trend_template IS NULL FROM messstelllen_abo_zuordnung` prüfen) |
| O9 | Wird `messstellen`.`nummer` noch für etwas benötigt, oder kann die Spalte entfallen? (betrifft D9) |
| ~~O10~~ | **entschieden** 13.08.2026 — beide Dateien entfernt. Die Logrotation übernimmt Monolog, `mailtest.php` prüfte nicht den Mailversand. |
| O11 | `bot/.htaccess` liegt in einem Verzeichnis, das von keinem Webserver ausgeliefert wird, und ist damit wirkungslos. Kann die Datei entfallen? |
| O2 | Soll der Log-Viewer ein eigenes Repository werden oder als Unterverzeichnis mitlaufen? |
| ~~O3~~ | **beantwortet** 13.08.2026 — Gleitkommazahlen, durchweg ohne Nachkommaanteil. Siehe B9. |
| O4 | Wird der Twitter/X-Kanal noch produktiv genutzt? |
| O5 | Sollen deutsche Bezeichner in Datenbankspalten mitmigriert werden, oder bleibt das Schema aus Bestandsgründen deutsch? |
