-- ============================================================================
--  Pegelbot — Ausgangsschema (Baseline)
--
--  Originalgetreue Abbildung des Produktionsstands vom 13.08.2026.
--  Erhoben auf MariaDB 10.11.18.
--
--  WICHTIG: Diese Datei wird nicht mehr verändert. Sie bildet den Zustand ab,
--  auf dem alle folgenden Migrationen aufsetzen. Bekannte Mängel des Schemas
--  sind unten als Kommentar markiert und werden über nummerierte Migrationen
--  behoben, nicht hier.
--
--  Bekannte Mängel (siehe SPEC.md, Abschnitt 6.3):
--    - Tippfehler im Tabellennamen `messstelllen_abo_zuordnung` (drei "l")
--    - Zeichensatz utf8mb3 statt utf8mb4, `abonnements_mastodon` sogar latin1
--    - `messwerte`.`messstellen_id` ist faelschlich AUTO_INCREMENT
--    - Zugangsdaten liegen im Klartext in den Abonnement-Tabellen
--    - Bezeichner sind deutsch, Zielkonvention ist Englisch
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- Stammdaten der ueberwachten Messstellen
-- ----------------------------------------------------------------------------
CREATE TABLE `messstellen` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `nummer` int(10) UNSIGNED NOT NULL,
  `uuid` varchar(50) NOT NULL,
  `update_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------------
-- Historie der abgerufenen Wasserstaende, Zeitpunkt in UTC, Wert in Zentimetern
-- ----------------------------------------------------------------------------
CREATE TABLE `messwerte` (
  `messstellen_id` int(10) UNSIGNED NOT NULL,
  `zeitpunkt` datetime NOT NULL,
  `messwert` smallint(6) NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------------
-- Je Messstelle genau ein Datensatz mit Vorlagen und Versandzeitpunkten
-- ----------------------------------------------------------------------------
CREATE TABLE `messstelllen_abo_zuordnung` (
  `messstellen_id` int(10) UNSIGNED NOT NULL,
  `letzter_zeitpunkt` datetime NOT NULL,
  `letzter_verlaufszeitpunkt` datetime NOT NULL,
  `message_template` varchar(2048) NOT NULL,
  `trend_template` varchar(2048) DEFAULT NULL,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------------
-- Registrierte Versandkanaele; der Name bestimmt Klassen- und Tabellennamen
-- ----------------------------------------------------------------------------
CREATE TABLE `abo_types` (
  `name` varchar(15) CHARACTER SET utf8mb3 COLLATE utf8mb3_german2_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------------
-- Abonnements je Kanal
-- ----------------------------------------------------------------------------
CREATE TABLE `abonnements_mail` (
  `mail_abo_id` int(10) UNSIGNED NOT NULL,
  `messstellen_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `beschreibung` varchar(2048) DEFAULT NULL,
  `aktiv` int(1) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE `abonnements_bluesky` (
  `bluesky_abo_id` int(10) UNSIGNED NOT NULL,
  `messstellen_id` int(10) UNSIGNED NOT NULL,
  `handle` varchar(255) NOT NULL,
  `passwort` varchar(255) NOT NULL,
  `beschreibung` varchar(2048) DEFAULT NULL,
  `aktiv` int(1) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- Abweichender Zeichensatz gegenueber allen anderen Tabellen, siehe SPEC.md
CREATE TABLE `abonnements_mastodon` (
  `mastodon_abo_id` int(10) UNSIGNED NOT NULL,
  `messstellen_id` int(10) UNSIGNED NOT NULL,
  `server` varchar(255) NOT NULL,
  `status_api` varchar(255) NOT NULL,
  `access_token` varchar(255) NOT NULL,
  `beschreibung` varchar(2048) DEFAULT NULL,
  `aktiv` int(1) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `abonnements_twitter` (
  `twitter_abo_id` int(10) UNSIGNED NOT NULL,
  `messstellen_id` int(10) UNSIGNED NOT NULL,
  `oauth_access_token` varchar(255) NOT NULL,
  `oauth_access_token_secret` varchar(255) NOT NULL,
  `consumer_key` varchar(255) NOT NULL,
  `consumer_secret` varchar(255) NOT NULL,
  `beschreibung` varchar(2048) DEFAULT NULL,
  `aktiv` int(1) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------------
-- Schluessel und Indizes
-- ----------------------------------------------------------------------------
ALTER TABLE `messstellen`
  ADD PRIMARY KEY (`id`) COMMENT 'Messstelle_ID_PK',
  ADD UNIQUE KEY `name_uq` (`name`),
  ADD UNIQUE KEY `nummer_uq` (`nummer`);

-- Der zusammengesetzte Primaerschluessel verhindert doppelte Messwerte
ALTER TABLE `messwerte`
  ADD PRIMARY KEY (`messstellen_id`,`zeitpunkt`),
  ADD KEY `zeitpunkt` (`zeitpunkt`);

ALTER TABLE `messstelllen_abo_zuordnung`
  ADD PRIMARY KEY (`messstellen_id`);

ALTER TABLE `abo_types`
  ADD PRIMARY KEY (`name`);

ALTER TABLE `abonnements_mail`
  ADD PRIMARY KEY (`mail_abo_id`),
  ADD KEY `abo_messstellen_id_fk` (`messstellen_id`),
  ADD KEY `abo_messtellen_mail_aktiv` (`aktiv`);

ALTER TABLE `abonnements_bluesky`
  ADD PRIMARY KEY (`bluesky_abo_id`),
  ADD KEY `abo_messstellen_id_fk3` (`messstellen_id`),
  ADD KEY `abo_messtellen_bluesky_aktiv` (`aktiv`);

ALTER TABLE `abonnements_mastodon`
  ADD PRIMARY KEY (`mastodon_abo_id`),
  ADD KEY `abo_messstellen_id_fk3` (`messstellen_id`),
  ADD KEY `abo_messtellen_mastodon_aktiv` (`aktiv`);

ALTER TABLE `abonnements_twitter`
  ADD PRIMARY KEY (`twitter_abo_id`),
  ADD KEY `abo_messstellen_id_fk2` (`messstellen_id`),
  ADD KEY `abo_messtellen_twitter_aktiv` (`aktiv`);

-- ----------------------------------------------------------------------------
-- Automatische Zaehler
-- ----------------------------------------------------------------------------
ALTER TABLE `messstellen`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- FEHLER im Bestand: `messstellen_id` ist ein Fremdschluessel und darf keinen
-- automatischen Zaehler haben. Wird hier originalgetreu abgebildet und in einer
-- eigenen Migration entfernt.
ALTER TABLE `messwerte`
  MODIFY `messstellen_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `abonnements_mail`
  MODIFY `mail_abo_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `abonnements_bluesky`
  MODIFY `bluesky_abo_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `abonnements_mastodon`
  MODIFY `mastodon_abo_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `abonnements_twitter`
  MODIFY `twitter_abo_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- ----------------------------------------------------------------------------
-- Fremdschluessel; saemtlich ohne ON DELETE / ON UPDATE, also RESTRICT
-- ----------------------------------------------------------------------------
ALTER TABLE `messwerte`
  ADD CONSTRAINT `messstellen_id_ctr` FOREIGN KEY (`messstellen_id`) REFERENCES `messstellen` (`id`);

ALTER TABLE `messstelllen_abo_zuordnung`
  ADD CONSTRAINT `abo_messstellen_ctr` FOREIGN KEY (`messstellen_id`) REFERENCES `messstellen` (`id`);

ALTER TABLE `abonnements_mail`
  ADD CONSTRAINT `abo_mail_messstellen_ctr` FOREIGN KEY (`messstellen_id`) REFERENCES `messstellen` (`id`);

ALTER TABLE `abonnements_bluesky`
  ADD CONSTRAINT `abo_bsky_messstellen_ctr` FOREIGN KEY (`messstellen_id`) REFERENCES `messstellen` (`id`);

ALTER TABLE `abonnements_mastodon`
  ADD CONSTRAINT `abo_mast_messstellen_ctr` FOREIGN KEY (`messstellen_id`) REFERENCES `messstellen` (`id`);

ALTER TABLE `abonnements_twitter`
  ADD CONSTRAINT `abo_twit_messstellen_ctr` FOREIGN KEY (`messstellen_id`) REFERENCES `messstellen` (`id`);

-- ----------------------------------------------------------------------------
-- Registrierte Versandkanaele
-- ----------------------------------------------------------------------------
INSERT INTO `abo_types` (`name`) VALUES ('mail');
INSERT INTO `abo_types` (`name`) VALUES ('bluesky');
INSERT INTO `abo_types` (`name`) VALUES ('mastodon');
INSERT INTO `abo_types` (`name`) VALUES ('twitter');

COMMIT;
