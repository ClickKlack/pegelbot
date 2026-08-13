# Entwicklungskonventionen — Pegelbot

Verbindliche Regeln für alle Arbeiten an diesem Projekt. Gilt für menschliche Beitragende
und für KI-Werkzeuge gleichermaßen.

Die fachliche Beschreibung des Systems steht in [SPEC.md](SPEC.md).

---

## 1. Plattform

| Bereich    | Vorgabe                                                        |
| ---------- | -------------------------------------------------------------- |
| PHP        | **ab 8.4** — neuere Sprachmittel dürfen genutzt werden          |
| Datenbank  | **MySQL / MariaDB**, Schema versioniert im Projekt              |
| Abhängigkeiten | Composer; `vendor/` wird **nicht** versioniert              |

`declare(strict_types=1);` steht in **jeder** PHP-Datei, ganz oben.

---

## 2. Sprache im Code

Diese Trennung ist strikt und gilt ausnahmslos:

| Element                          | Sprache     |
| -------------------------------- | ----------- |
| Klassennamen                     | **Englisch** |
| Methoden- und Funktionsnamen     | **Englisch** |
| Variablen und Parameter          | **Englisch** |
| Konstanten                       | **Englisch** |
| Datenbanktabellen und -spalten   | **Englisch** |
| Dateinamen                       | **Englisch** |
| **Kommentare**                   | **Deutsch**  |
| **PHPDoc-Blöcke**                | **Deutsch**  |
| Commit-Nachrichten               | Deutsch      |
| Dokumentation (`*.md`)           | Deutsch      |

Log- und Benutzerausgaben bleiben deutsch — sie sind Produktinhalt, nicht Code.

### 2.1 Beispiel

```php
<?php

declare(strict_types=1);

namespace PegelBot;

/**
 * Ermittelt den Zeitpunkt der zuletzt gespeicherten Messung einer Messstelle.
 *
 * Existiert noch keine Messung, wird als Startpunkt "jetzt minus 24 Stunden"
 * geliefert, damit der erste Lauf einen sinnvollen Abrufzeitraum hat.
 */
public function getLastMeasurementTimestamp(int $stationId): DateTimeImmutable
{
    // Vergleichswert bewusst in UTC halten, Umrechnung erst bei der Ausgabe
    $lastTimestamp = $this->repository->findLatestTimestamp($stationId);

    return $lastTimestamp ?? $this->clock->now()->sub(new DateInterval('P1D'));
}
```

### 2.2 Umgang mit dem deutschen Bestand

Der vorhandene Code und das Schema verwenden durchgängig deutsche Bezeichner
(`Messstelle`, `messwerte`, `letzter_zeitpunkt`, …). Regeln dazu:

- **Neuer Code ist ausnahmslos englisch.**
- Bestehende Bezeichner werden **schrittweise** umbenannt, nie in einem Rundumschlag.
  Eine Umbenennung ist ein eigener Commit ohne Verhaltensänderung.
- Ein Modul wird erst umbenannt, wenn es von Tests abgedeckt ist.
- Schema-Umbenennungen laufen ausschließlich über nummerierte Migrationen.

### 2.3 Deutsche Sonderzeichen

Umlaute sind in Kommentaren und Dokumentation erwünscht. In Bezeichnern, Dateinamen und
Schema-Objekten sind sie unzulässig.

---

## 3. Tests

**Jede Änderung wird von Unit-Tests begleitet.** Ohne Test kein Commit.

- Testrahmen: **PHPUnit**
- Ablage: `tests/`, Struktur spiegelt `src/`
- Namensschema: `<KlasseUnterTest>Test.php`
- Testmethoden sind englisch benannt, ihre Kommentare deutsch

Was ein Test abdecken muss:

- **Neue Funktion** → Tests für den Normalfall und mindestens einen Randfall
- **Fehlerbehebung** → zuerst ein Test, der den Fehler nachweist, dann die Behebung
- **Refactoring** → bestehende Tests müssen unverändert grün bleiben

Externe Systeme werden **niemals** in Tests angesprochen. PEGELONLINE, SMTP, Bluesky,
Mastodon und Twitter werden durch Testdoubles ersetzt. Das gilt auch für die aktuelle
Systemzeit: Zeitabhängige Logik erhält eine einschleusbare Zeitquelle.

```bash
composer test          # gesamte Testsuite
composer test -- --filter MessageBuilder
```

---

## 4. Smoke-Test durch den Benutzer

Unit-Tests reichen nicht aus. **Jede Änderung wird zusätzlich vom Benutzer in einem
Smoke-Test bestätigt, bevor sie als abgeschlossen gilt.**

Ablauf:

1. Änderung umsetzen, Unit-Tests grün.
2. Dem Benutzer eine **konkrete Anleitung** geben: was auszuführen ist und welches
   Ergebnis erwartet wird.
3. Die Rückmeldung abwarten.
4. Erst danach gilt die Aufgabe als erledigt.

Eine Änderung darf **nicht** als fertig gemeldet werden, solange der Smoke-Test aussteht.
Fällt er negativ aus, wird nachgebessert und erneut vorgelegt.

---

## 5. Git

### 5.1 Commit-Nachrichten

Deutsch, Betreffzeile im Imperativ, höchstens 72 Zeichen. Bei Bedarf ein Rumpf, der
begründet **warum** — nicht was.

```
Fehlenden ServerException-Import in WSAServices ergaenzt

Ohne den Import loeste der Klassenname im Namensraum WSA auf und
griff nie. Jede 5xx-Antwort der PEGELONLINE-API beendete dadurch
den kompletten Lauf mit einem nicht gefangenen Fehler.
```

### 5.2 Keine Co-Autoren-Zeile

Commits enthalten **niemals** eine `Co-authored-by:`-Zeile und keinen Hinweis auf
verwendete KI-Werkzeuge. Der Nachrichtenrumpf endet mit dem fachlichen Inhalt.

### 5.3 Umfang

Ein Commit, ein Anliegen. Fehlerbehebung, Refactoring und Formatierung werden nicht
vermischt.

### 5.4 Was nie versioniert wird

`vendor/` · `logs/` · `tmp/` · Konfigurationsdateien mit Zugangsdaten · `.DS_Store` ·
Datenbankauszüge mit Echtdaten

Zu jeder Konfigurationsdatei gehört eine versionierte Vorlage `*.sample.php` ohne
Geheimnisse.

---

## 6. Geheimnisse

- Zugangsdaten stehen **ausschließlich** in nicht versionierten Konfigurationsdateien.
- Kein Kennwort, kein Token und kein Schlüssel steht je im Quelltext.
- Absolute Serverpfade sind Konfiguration, keine Konstanten im Code.
- Gerät ein Geheimnis versehentlich in den Quelltext, wird es **gewechselt** — Entfernen
  allein genügt nicht.
- Personenbezogene Daten (E-Mail-Adressen von Abonnenten) werden nicht auf `INFO`-Ebene
  protokolliert.

---

## 7. Datenbank

- Jede Schemaänderung erfolgt über eine **nummerierte Migration** in `migrations/`.
- Migrationen werden nach dem Übertragen nie mehr verändert.
- Neue Objekte durchgängig `utf8mb4` mit `utf8mb4_unicode_ci`.
- Neue Tabellen und Spalten englisch benannt, `snake_case`.
- Schreibende Mehrschrittvorgänge laufen in einer Transaktion.
- Abfragen werden ausschließlich mit gebundenen Parametern gebaut; Tabellen- und
  Klassennamen werden nicht aus Datenbankinhalten zusammengesetzt.

---

## 8. Code-Stil

- PSR-12
- Klassen `PascalCase`, Methoden und Variablen `camelCase`, Konstanten `UPPER_SNAKE_CASE`
- Objekte werden **nicht** per Referenz übergeben — PHP übergibt bereits Handles
- Abhängigkeiten werden über den Konstruktor eingeschleust, nicht intern erzeugt
- Keine statischen Methoden für Verhalten, das ersetzbar sein muss
- Fachlogik erzeugt keine Ausgabe. Was der Benutzer sehen soll, geht über den Logger
  oder eine ausdrückliche Ausgabeschicht.
- `DateTimeImmutable` statt `DateTime`
- Zeitstempel werden intern in UTC gehalten und erst bei der Ausgabe nach
  `Europe/Berlin` umgerechnet

---

## 9. Arbeitsweise für KI-Werkzeuge

1. Vor jeder Änderung [SPEC.md](SPEC.md) lesen, insbesondere die Abschnitte zu Mängeln
   und Testbarkeit.
2. Nur den beauftragten Umfang bearbeiten. Nebenbefunde werden gemeldet, nicht
   ungefragt behoben.
3. Zu jeder Änderung gehören Unit-Tests (Abschnitt 3).
4. Nach jeder Änderung eine Smoke-Test-Anleitung liefern und die Rückmeldung abwarten
   (Abschnitt 4).
5. Nicht selbstständig committen oder übertragen, ohne dass der Benutzer es verlangt.
6. Erkenntnisse, die dauerhaft gelten, in `SPEC.md` nachtragen — nicht nur in der
   Antwort erwähnen.
7. Offene Punkte gehören in Abschnitt 12 von `SPEC.md`.
