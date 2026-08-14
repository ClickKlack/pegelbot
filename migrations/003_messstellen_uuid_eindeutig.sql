-- ============================================================================
--  003 - Eindeutigkeitsschluessel auf messstellen.uuid
--
--  Befund D4. Die UUID ist der fachliche Schluessel zur Schnittstelle von
--  PEGELONLINE - sie bestimmt, welche Messreihe abgerufen wird. Bislang hatte
--  die Spalte weder Index noch Eindeutigkeitsbedingung. Zwei Messstellen
--  koennten dieselbe UUID fuehren und wuerden dann dieselben Werte doppelt
--  einsammeln, ohne dass es auffiele.
--
--  name und nummer sind bereits eindeutig; uuid war die einzige Luecke.
--
--  Voraussetzung: keine doppelten UUIDs. Bei Bedarf vorher pruefen mit
--    SELECT uuid, COUNT(*) FROM messstellen GROUP BY uuid HAVING COUNT(*) > 1;
-- ============================================================================

ALTER TABLE `messstellen`
  ADD UNIQUE KEY `uuid_uq` (`uuid`);
