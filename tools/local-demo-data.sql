-- ============================================================================
--  Demodaten fuer eine lokale Entwicklungs- und Testdatenbank.
--
--  Einspielen nach migrations/000_baseline_schema.sql:
--
--      mariadb pegelbot_local < tools/local-demo-data.sql
--
--  WICHTIG: Es werden bewusst KEINE Abonnements angelegt. Ein lokaler Botlauf
--  ruft dadurch zwar die echten Messwerte von PEGELONLINE ab und durchlaeuft
--  alle drei Phasen, verschickt aber weder E-Mails noch Beitraege in soziale
--  Netze. Wer Abonnements ergaenzt, verschickt echte Nachrichten.
--
--  Das Skript ist wiederholbar: Es raeumt zuerst auf.
-- ============================================================================

START TRANSACTION;

-- Aufraeumen in Fremdschluesselreihenfolge
DELETE FROM `abonnements_mail`;
DELETE FROM `abonnements_bluesky`;
DELETE FROM `abonnements_mastodon`;
DELETE FROM `abonnements_twitter`;
DELETE FROM `messwerte`;
DELETE FROM `messstelllen_abo_zuordnung`;
DELETE FROM `messstellen`;

-- Die drei produktiv ueberwachten Messstellen an der Elbe
INSERT INTO `messstellen` (`id`, `name`, `nummer`, `uuid`, `update_active`) VALUES
  (1, 'MAGDEBURG-STROMBRÜCKE', 501090, 'ccccb57f-a2f9-4183-ae88-5710d3afaefd', 1),
  (2, 'MAGDEBURG-BUCKAU',      501080, 'b8567c1e-8610-4c2b-a240-65e8a74919fa', 1),
  (3, 'ROTHENSEE',             501091, 'e30f2e83-b80b-4b96-8f39-fa60317afcc7', 1);

-- Vorlagen und Versandzeitpunkte.
--
-- letzter_zeitpunkt liegt zwei Tage zurueck, damit Phase 2 ausloest.
-- letzter_verlaufszeitpunkt liegt acht Tage zurueck, damit auch Phase 3
-- ausloest und die Ganglinie abgerufen wird.
INSERT INTO `messstelllen_abo_zuordnung`
  (`messstellen_id`, `letzter_zeitpunkt`, `letzter_verlaufszeitpunkt`, `message_template`, `trend_template`) VALUES
  (1,
   DATE_ADD(UTC_TIMESTAMP(), INTERVAL -2 DAY),
   DATE_ADD(UTC_TIMESTAMP(), INTERVAL -8 DAY),
   'Pegel {MESSPUNKT}: {MESSWERT} cm am {DATE} um {TIME} Uhr. {TENDENZ}\r\n24h: {ENTWICKLUNG_24h} cm, 7d: {ENTWICKLUNG_7d} cm\r\n\r\n#elbe #magdeburg #strombrücke',
   'Aktualisierte Ganglinie zum Messpunkt {MESSPUNKT}\r\n\r\n#elbe #magdeburg #strombrücke'),
  (2,
   DATE_ADD(UTC_TIMESTAMP(), INTERVAL -2 DAY),
   DATE_ADD(UTC_TIMESTAMP(), INTERVAL -8 DAY),
   'Pegel {MESSPUNKT}: {MESSWERT} cm am {DATE} um {TIME} Uhr. {TENDENZ}\r\n24h: {ENTWICKLUNG_24h} cm, 7d: {ENTWICKLUNG_7d} cm\r\n\r\n#elbe #magdeburg #buckau',
   'Aktualisierte Ganglinie zum Messpunkt {MESSPUNKT}\r\n\r\n#elbe #magdeburg #buckau'),
  (3,
   DATE_ADD(UTC_TIMESTAMP(), INTERVAL -2 DAY),
   DATE_ADD(UTC_TIMESTAMP(), INTERVAL -8 DAY),
   'Pegel {MESSPUNKT}: {MESSWERT} cm am {DATE} um {TIME} Uhr. {TENDENZ}\r\n24h: {ENTWICKLUNG_24h} cm, 7d: {ENTWICKLUNG_7d} cm\r\n\r\n#elbe #magdeburg #rothensee',
   'Aktualisierte Ganglinie zum Messpunkt {MESSPUNKT}\r\n\r\n#elbe #magdeburg #rothensee');

COMMIT;
