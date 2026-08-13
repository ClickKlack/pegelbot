<?php

declare(strict_types=1);

// ============================================================================
//  Konfiguration des Log-Betrachters
//
//  Diese Datei liegt bewusst eine Ebene oberhalb von public/ und ist damit
//  nicht ueber HTTP erreichbar. Zum Einrichten kopieren:
//
//      cp config.sample.php config.php
//
//  Das Kennwort steht hier nur als Hash. Erzeugen mit:
//
//      php bin/hash-password.php
// ============================================================================

return [
    // Verzeichnis mit den Logdateien des Bots, mit abschliessendem Schraegstrich
    'logFolder' => '/pfad/zum/bot/logs/',

    // Dateinamenpraefix; erwartet wird das Muster praefix-YYYY-MM-DD.log
    'logPrefix' => 'pegelbot',

    // Kennwort-Hash aus password_hash(). Ohne gueltigen Hash verweigert der
    // Betrachter den Dienst - die Logdateien enthalten E-Mail-Adressen von
    // Abonnenten und duerfen nicht ungeschuetzt ausgeliefert werden.
    'passwordHash' => '',

    // Fehlversuche bis zur Sperre und Dauer der Sperre in Sekunden
    'maxLoginAttempts' => 5,
    'lockoutSeconds'   => 900,

    // Ablage der Fehlversuchszaehler. Muss ausserhalb des Dokumentenstamms
    // liegen und fuer den Webserver beschreibbar sein.
    'stateFolder' => __DIR__ . '/var/auth',

    // Maximale Anzahl Zeilen, die je Datei ausgeliefert wird
    'tailLines' => 1000,

    // Intervall der automatischen Aktualisierung in Millisekunden, mindestens 500
    'interval'  => 3000,

    // Zeitzone fuer die Datumsanzeige
    'timezone'  => 'Europe/Berlin',
];
