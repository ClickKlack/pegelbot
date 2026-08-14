-- ============================================================================
--  001 - AUTO_INCREMENT von messwerte.messstellen_id entfernen
--
--  Befund D1. Die Spalte ist ein Fremdschluessel auf messstellen.id und Teil des
--  zusammengesetzten Primaerschluessels. Ein automatischer Zaehler ist dort
--  fachlich falsch: Solange der Code den Wert stets ausdruecklich setzt, faellt
--  es nicht auf; ein Einfuegevorgang ohne Angabe erzeugt jedoch eine erfundene
--  Messstellennummer und scheitert erst am Fremdschluessel.
--
--  ALGORITHM=INSTANT ist ausdruecklich gesetzt. Auf MariaDB 10.11 ist die
--  Aenderung eine reine Metadaten-Operation, trotz rund 905.000 Zeilen. Sollte
--  eine kuenftige Fassung das nicht mehr sofort koennen, bricht die Migration
--  hoerbar ab, statt die Tabelle stillschweigend minutenlang zu sperren.
-- ============================================================================

ALTER TABLE `messwerte`
  MODIFY `messstellen_id` int(10) UNSIGNED NOT NULL,
  ALGORITHM=INSTANT;
