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
//  Die Anmeldung selbst erledigt der Webserver per HTTP-Basic-Auth, siehe
//  public/.htaccess.sample. Hier steht kein Kennwort mehr.
// ============================================================================

return [
    // Verzeichnis mit den Logdateien des Bots, mit abschliessendem Schraegstrich
    'logFolder' => '/pfad/zum/bot/logs/',

    // Dateinamenpraefix; erwartet wird das Muster praefix-YYYY-MM-DD.log
    'logPrefix' => 'pegelbot',

    // Bricht ab, wenn der Webserver keinen angemeldeten Benutzer durchreicht.
    // Nur abschalten, wenn der Zugriff anderweitig eingeschraenkt ist, etwa
    // ueber eine IP-Freigabe. Ohne Schutz waeren die Logdateien samt der darin
    // enthaltenen E-Mail-Adressen oeffentlich abrufbar.
    'requireAuth' => true,

    // Maximale Anzahl Zeilen, die je Datei ausgeliefert wird
    'tailLines' => 1000,

    // Intervall der automatischen Aktualisierung in Millisekunden, mindestens 500
    'interval'  => 3000,

    // Zeitzone fuer die Datumsanzeige
    'timezone'  => 'Europe/Berlin',
];
