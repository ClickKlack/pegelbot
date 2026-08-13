--V 2.0
ALTER TABLE `messstellen` ADD `uuid` VARCHAR(50) NOT NULL AFTER `nummer`; 

UPDATE `messstellen` SET `uuid` = 'e30f2e83-b80b-4b96-8f39-fa60317afcc7' WHERE `name` = 'ROTHENSEE';
UPDATE `messstellen` SET `uuid` = 'b8567c1e-8610-4c2b-a240-65e8a74919fa' WHERE `name` = 'MAGDEBURG-BUCKAU';
UPDATE `messstellen` SET `uuid` = 'ccccb57f-a2f9-4183-ae88-5710d3afaefd' WHERE `name` = 'MAGDEBURG-STROMBRÜCKE';

CREATE TABLE `abo_types` (
  `name` varchar(15) CHARACTER SET utf8 COLLATE utf8_german2_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `abo_types`
  ADD PRIMARY KEY (`name`);

INSERT INTO `abo_types` (`name`) VALUES ('mail'); 
INSERT INTO `abo_types` (`name`) VALUES ('bluesky'); 
INSERT INTO `abo_types` (`name`) VALUES ('mastodon'); 
INSERT INTO `abo_types` (`name`) VALUES ('twitter'); 

ALTER TABLE `abonnements_mail` ADD `aktiv` INT(1) UNSIGNED NOT NULL DEFAULT '1', ADD INDEX `abo_messtellen_mail_aktiv` (`aktiv`); 
ALTER TABLE `abonnements_bluesky` ADD `aktiv` INT(1) UNSIGNED NOT NULL DEFAULT '1', ADD INDEX `abo_messtellen_bluesky_aktiv` (`aktiv`);
ALTER TABLE `abonnements_mastodon` ADD `aktiv` INT(1) UNSIGNED NOT NULL DEFAULT '1', ADD INDEX `abo_messtellen_mastodon_aktiv` (`aktiv`);
ALTER TABLE `abonnements_twitter` ADD `aktiv` INT(1) UNSIGNED NOT NULL DEFAULT '1', ADD INDEX `abo_messtellen_twitter_aktiv` (`aktiv`);

ALTER TABLE `messstelllen_abo_zuordnung` ADD `letzter_verlaufszeitpunkt` DATETIME NULL AFTER `letzter_zeitpunkt`; 
UPDATE `messstelllen_abo_zuordnung` SET `letzter_verlaufszeitpunkt` = DATE_ADD(CURRENT_DATE, INTERVAL -7 DAY);
ALTER TABLE `messstelllen_abo_zuordnung` CHANGE `letzter_verlaufszeitpunkt` `letzter_verlaufszeitpunkt` DATETIME NOT NULL; 

ALTER TABLE `messstelllen_abo_zuordnung` ADD `trend_template` VARCHAR(2048) CHARACTER SET utf8 COLLATE utf8_general_ci NULL AFTER `message_template`; 

UPDATE `messstelllen_abo_zuordnung` SET `trend_template` = 'Aktualisierte Ganglinie zum Messpunkt {MESSPUNKT}\r\n\r\n#elbe #magdeburg #strombrücke' WHERE `messstelllen_abo_zuordnung`.`messstellen_id` = 1;
UPDATE `messstelllen_abo_zuordnung` SET `trend_template` = 'Aktualisierte Ganglinie zum Messpunkt {MESSPUNKT}\r\n\r\n#elbe #magdeburg #rothensee' WHERE `messstelllen_abo_zuordnung`.`messstellen_id` = 3;

COMMIT;