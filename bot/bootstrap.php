<?php
// bootstrap.php
declare(strict_types=1);

// Composer-Autoload
// Pfade bewusst ueber __DIR__ aufloesen, damit der Bot unabhaengig vom
// Arbeitsverzeichnis startet und der Cron-Eintrag kein "cd" mehr benoetigt.
require_once __DIR__ . "/vendor/autoload.php";

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