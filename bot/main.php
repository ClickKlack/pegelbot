<?php

declare(strict_types=1);

// Der Bot ist ein reines Kommandozeilenprogramm. Die Wache schuetzt davor, dass
// eine spaetere Umkonfiguration des Hosters das Verzeichnis in einen
// Dokumentenstamm verwandelt und Fremde damit Laeufe ausloesen koennten.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Programm laeuft ausschliesslich auf der Kommandozeile.\n");
}

// Basis-Konfigurationen laden
require_once __DIR__ . "/bootstrap.php";

$controller = new PegelBot\Controller($connection, $logger, $api, $clock, $trendPolicy);
$controller->run();
