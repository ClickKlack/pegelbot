-- ============================================================================
--  002 - Gesamtes Schema auf utf8mb4
--
--  Befunde D2 und D3. Das Schema stand auf utf8mb3, das in MariaDB veraltet ist
--  und keine Zeichen ausserhalb der Basic Multilingual Plane speichern kann -
--  ein Emoji in einer Nachrichtenvorlage haette den Einfuegevorgang abgebrochen.
--  abonnements_mastodon stand als einzige Tabelle sogar auf latin1, wodurch
--  Umlaute im Beschreibungsfeld fehlerhaft abgelegt wurden.
--
--  Zwei verschiedene Anweisungen, mit Absicht:
--
--    CONVERT TO CHARACTER SET wandelt auch den Inhalt der Zeichenspalten um und
--    baut die Tabelle dafuer neu auf. Noetig fuer alle Tabellen mit Textspalten;
--    die haben hier jeweils eine Handvoll Zeilen.
--
--    DEFAULT CHARACTER SET aendert nur die Vorgabe fuer kuenftige Spalten und
--    ist eine Metadaten-Operation. Genau richtig fuer messwerte: Die Tabelle hat
--    ueberhaupt keine Zeichenspalten, es gibt nichts umzuwandeln - und mit rund
--    905.000 Zeilen waere ein Neuaufbau der einzige teure Schritt gewesen.
-- ============================================================================

-- Tabellen mit Zeichenspalten: Inhalt mitwandeln
ALTER TABLE `messstellen`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `messstelllen_abo_zuordnung`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `abo_types`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `abonnements_mail`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `abonnements_bluesky`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Diese Tabelle stand auf latin1, siehe Befund D2
ALTER TABLE `abonnements_mastodon`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `abonnements_twitter`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ohne Zeichenspalten: nur die Vorgabe, sofort wirksam
ALTER TABLE `messwerte`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  ALGORITHM=INSTANT;
