<?php
// bootstrap.php
declare(strict_types=1);

// Composer-Autoload aus dem Projektwurzelverzeichnis.
//
// Das Projekt hat bewusst nur eine composer.json: Getrennte Abhaengigkeiten je
// Komponente waren binnen kurzem auseinandergelaufen, sodass die Tests gegen
// andere Fassungen liefen als der Produktivbetrieb.
//
// Pfade bewusst ueber __DIR__ aufloesen, damit der Bot unabhaengig vom
// Arbeitsverzeichnis startet und der Cron-Eintrag kein "cd" benoetigt.
require_once __DIR__ . "/../vendor/autoload.php";

use Doctrine\DBAL\DriverManager;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Doctrine\DBAL\Exception;

// Konfigurationsdatei; enthaelt Zugangsdaten und wird nicht versioniert
require_once __DIR__ . "/config/pegelbot-config.php";

// the connection configuration
$dbParams = [
    'driver' => DB_DRIVER,
    'dbname' => DB_NAME,
    'user' => DB_USER,
    'password' => DB_PASSWORD,
    'host' => DB_HOST,
    'charset'  => 'utf8'
];

// create a log channel
$logger = new Logger('pegelbot');
$logger->pushHandler(new RotatingFileHandler(__DIR__.'/logs/pegelbot.log', 14, DEBUG_LEVEL));

// Zugriff auf PEGELONLINE; der HTTP-Client wird hier erzeugt und
// hereingereicht, damit die API-Klasse in Tests ersetzbar bleibt.
$api = new WSA\PegelOnlineApi(
    new GuzzleHttp\Client(['base_uri' => WSA\PegelOnlineApi::API_URL]),
    $logger
);

// Uhr und Regelwerk der Ganglinien. Beides wird hereingereicht, damit die
// Fachlogik nicht selbst "jetzt" bestimmt und dadurch pruefbar bleibt.
//
// Die Zeitzone steht bewusst auf UTC: Das entspricht dem bisherigen Verhalten.
// Gemeint sind die Grenzen 6 und 22 als Ortszeit - siehe Befund B3.
$clock = new PegelBot\SystemClock();
$trendPolicy = new PegelBot\TrendPolicy('UTC');

// Datenbankverbindung
$connection = DriverManager::getConnection($dbParams);

// Connection überprüfen
try {
    $dual = $connection->fetchAllAssociative('SELECT 1 FROM dual');
} 
catch(Exception $e){
    $logger->error("Datenbank-Verbindungsfehler", ['error'=>$e->getMessage()]);
    echo "FEHLER!";
    exit;
}

?>