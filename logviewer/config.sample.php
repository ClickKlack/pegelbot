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
//  config.php enthaelt das Zugangskennwort und wird nicht versioniert.
// ============================================================================

return [
    // Verzeichnis mit den Logdateien des Bots, mit abschliessendem Schraegstrich
    'logFolder' => '/pfad/zum/bot/logs/',

    // Dateinamenpraefix; erwartet wird das Muster praefix-YYYY-MM-DD.log
    'logPrefix' => 'pegelbot',

    // Zugangskennwort. Leer lassen schaltet den Schutz ab - nur sinnvoll,
    // wenn der Zugriff bereits ueber den Webserver abgesichert ist.
    'password'  => 'hier-ein-eigenes-kennwort-eintragen',

    // Maximale Anzahl Zeilen, die je Datei ausgeliefert wird
    'tailLines' => 1000,

    // Intervall der automatischen Aktualisierung in Millisekunden, mindestens 500
    'interval'  => 3000,

    // Zeitzone fuer die Datumsanzeige
    'timezone'  => 'Europe/Berlin',
];
