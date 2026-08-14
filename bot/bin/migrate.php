<?php

declare(strict_types=1);

// ============================================================================
//  Wendet ausstehende Datenbankmigrationen an.
//
//      php bot/bin/migrate.php --status     zeigt den Stand, aendert nichts
//      php bot/bin/migrate.php --dry-run    zeigt die Anweisungen, fuehrt sie nicht aus
//      php bot/bin/migrate.php              wendet die ausstehenden an
//      php bot/bin/migrate.php --baseline   einmalig fuer eine bestehende Datenbank
//
//  Die Entscheidungslogik steckt in PegelBot\MigrationSet und ist dort durch
//  Unit-Tests abgedeckt. Diese Datei fuehrt nur aus.
//
//  Bewusst NICHT Teil von scripts/deploy.sh: Ein Schritt, der sich nicht
//  zurueckrollen laesst, soll bewusst ausgeloest werden.
// ============================================================================

use PegelBot\MigrationSet;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Programm laeuft ausschliesslich auf der Kommandozeile.\n");
}

require_once __DIR__ . '/../bootstrap.php';

$migrationsFolder = __DIR__ . '/../../migrations';
$set = new MigrationSet($migrationsFolder);

$statusOnly  = in_array('--status', $argv, true);
$dryRun      = in_array('--dry-run', $argv, true);
$markBaseline = in_array('--baseline', $argv, true);

/** Bricht mit Meldung ab. */
$fail = static function (string $message): never {
    fwrite(STDERR, "\nAbbruch: {$message}\n\n");
    exit(1);
};

// ---------------------------------------------------------------------------
//  Versionstabelle
// ---------------------------------------------------------------------------

$connection->executeStatement(
    'CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `version`    varchar(20)  NOT NULL,
        `checksum`   varchar(64)  NOT NULL,
        `applied_at` datetime     NOT NULL,
        PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

/** @var array<string, string> Version => Pruefwert */
$applied = [];
foreach ($connection->fetchAllAssociative('SELECT version, checksum FROM schema_migrations') as $row) {
    $applied[$row['version']] = $row['checksum'];
}

$appliedVersions = array_keys($applied);

// ---------------------------------------------------------------------------
//  Bestehende Datenbank ohne Versionstabelle
// ---------------------------------------------------------------------------

$hasTables = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?',
    ['messstellen']
) > 0;

if ($hasTables && $applied === [] && !$markBaseline) {
    $fail(
        "Die Datenbank enthaelt bereits Tabellen, aber keine Versionsvermerke.\n"
        . "         Das ist der Zustand einer Datenbank, die vor Einfuehrung der Migrationen\n"
        . "         angelegt wurde. Einmalig aufnehmen mit:\n\n"
        . "             php bot/bin/migrate.php --baseline\n\n"
        . "         Das vermerkt die Baseline als angewandt, ohne sie auszufuehren."
    );
}

if ($markBaseline) {
    if (isset($applied[MigrationSet::BASELINE_VERSION])) {
        echo "Die Baseline ist bereits vermerkt, nichts zu tun.\n";
        exit(0);
    }

    $connection->insert('schema_migrations', [
        'version'    => MigrationSet::BASELINE_VERSION,
        'checksum'   => $set->checksum(MigrationSet::BASELINE_VERSION),
        'applied_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ]);

    echo "Baseline als angewandt vermerkt, ohne sie auszufuehren.\n";
    echo "Weiter mit: php bot/bin/migrate.php --status\n";
    exit(0);
}

// ---------------------------------------------------------------------------
//  Unversehrtheit der bereits angewandten Migrationen
// ---------------------------------------------------------------------------

$missing = $set->missingFiles($appliedVersions);
if ($missing !== []) {
    $fail(
        'Angewandte Migrationen fehlen im Verzeichnis: ' . implode(', ', $missing) . "\n"
        . '         Wurde eine Datei geloescht oder umbenannt?'
    );
}

$changed = $set->changedSinceApplied($applied);
if ($changed !== []) {
    $fail(
        'Bereits angewandte Migrationen wurden nachtraeglich veraendert: '
        . implode(', ', $changed) . "\n"
        . "         Migrationen bleiben nach dem Anwenden unveraendert. Die Aenderung\n"
        . '         gehoert in eine neue Migration.'
    );
}

// ---------------------------------------------------------------------------
//  Stand anzeigen
// ---------------------------------------------------------------------------

$pending = $set->pending($appliedVersions);

echo "\nDatenbank: " . DB_NAME . "\n\n";

foreach ($set->all() as $version => $file) {
    $mark = isset($applied[$version]) ? '[x]' : '[ ]';
    printf("  %s %s  %s\n", $mark, $version, $set->describe($version));
}

if ($pending === []) {
    echo "\nAlle Migrationen sind angewandt.\n\n";
    exit(0);
}

printf("\n%d Migration(en) stehen aus.\n", count($pending));

if ($statusOnly) {
    exit(0);
}

// ---------------------------------------------------------------------------
//  Anwenden
// ---------------------------------------------------------------------------

foreach ($pending as $version => $file) {
    printf("\n== %s  %s\n", $version, $set->describe($version));

    foreach ($set->statementsOf($version) as $statement) {
        $preview = preg_replace('/\s+/', ' ', $statement);

        if ($dryRun) {
            echo "   [Probelauf] {$preview}\n";
            continue;
        }

        echo "   {$preview}\n";

        try {
            $connection->executeStatement($statement);
        } catch (Throwable $e) {
            // DDL ist in MariaDB nicht transaktional; bereits ausgefuehrte
            // Anweisungen dieser Migration bleiben wirksam. Deshalb hier
            // abbrechen und den Stand ausdruecklich benennen.
            $fail(
                "Migration {$version} ist gescheitert:\n"
                . '         ' . $e->getMessage() . "\n\n"
                . "         Die Migration ist NICHT vermerkt. Vorherige Anweisungen dieser\n"
                . "         Datei sind bereits wirksam - DDL laesst sich nicht zurueckrollen.\n"
                . '         Zustand pruefen, bevor erneut ausgefuehrt wird.'
            );
        }
    }

    if ($dryRun) {
        continue;
    }

    $connection->insert('schema_migrations', [
        'version'    => $version,
        'checksum'   => $set->checksum($version),
        'applied_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ]);

    echo "   vermerkt\n";
}

echo $dryRun
    ? "\nProbelauf beendet. Es wurde nichts veraendert.\n\n"
    : "\nFertig.\n\n";
