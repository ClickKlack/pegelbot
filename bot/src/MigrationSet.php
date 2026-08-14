<?php

declare(strict_types=1);

namespace PegelBot;

/**
 * Verwaltet die Sammlung der Migrationsdateien eines Verzeichnisses.
 *
 * Die Klasse fasst alles, was sich ohne Datenbank entscheiden laesst: welche
 * Dateien es gibt, in welcher Reihenfolge sie gelten, welche noch ausstehen und
 * ob eine bereits angewandte Datei nachtraeglich veraendert wurde. Der
 * eigentliche Laeufer in bin/migrate.php bleibt dadurch duenn.
 *
 * Erwartetes Namensmuster: NNN_beschreibung.sql, etwa 001_auto_increment.sql.
 * Unterverzeichnisse werden bewusst nicht durchsucht - in migrations/legacy/
 * liegen historische Skripte, die nicht mehr angewandt werden duerfen.
 */
final class MigrationSet
{
    /** Die Baseline bildet den Ausgangszustand ab und wird gesondert behandelt. */
    public const BASELINE_VERSION = '000';

    private readonly string $folder;

    public function __construct(string $folder)
    {
        $this->folder = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Alle Migrationen des Verzeichnisses, aufsteigend nach Version.
     *
     * @return array<string, string> Version => absoluter Pfad
     */
    public function all(): array
    {
        $files = glob($this->folder . '[0-9][0-9][0-9]_*.sql');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $migrations = [];

        foreach ($files as $file) {
            $migrations[$this->versionOf($file)] = $file;
        }

        return $migrations;
    }

    /**
     * Version aus dem Dateinamen, also der Teil vor dem ersten Unterstrich.
     */
    public function versionOf(string $file): string
    {
        return substr(basename($file), 0, 3);
    }

    /**
     * Beschreibender Teil des Dateinamens, fuer die Ausgabe aufbereitet.
     */
    public function describe(string $version): string
    {
        $file = $this->all()[$version] ?? null;

        if ($file === null) {
            return '(unbekannt)';
        }

        return str_replace('_', ' ', substr(basename($file, '.sql'), 4));
    }

    /**
     * Pruefwert einer Migrationsdatei.
     *
     * Dient dazu, nachtraegliche Aenderungen an bereits angewandten Migrationen
     * zu erkennen. Die Konvention verlangt, dass sie unveraendert bleiben - der
     * Pruefwert macht daraus eine Zusicherung statt eines guten Vorsatzes.
     */
    public function checksum(string $version): string
    {
        $file = $this->all()[$version] ?? null;

        if ($file === null) {
            throw new \RuntimeException("Migration {$version} existiert nicht.");
        }

        return hash_file('sha256', $file);
    }

    /**
     * Migrationen, die noch nicht angewandt wurden.
     *
     * @param list<string> $appliedVersions
     *
     * @return array<string, string> Version => Pfad
     */
    public function pending(array $appliedVersions): array
    {
        return array_diff_key($this->all(), array_flip($appliedVersions));
    }

    /**
     * Bereits angewandte Migrationen, deren Datei sich seither geaendert hat.
     *
     * @param array<string, string> $appliedChecksums Version => Pruefwert
     *
     * @return list<string> betroffene Versionen
     */
    public function changedSinceApplied(array $appliedChecksums): array
    {
        $changed = [];
        $available = $this->all();

        foreach ($appliedChecksums as $version => $recorded) {
            if (!isset($available[$version])) {
                // Datei entfernt: ebenfalls eine Abweichung, aber gesondert
                continue;
            }

            if (!hash_equals($recorded, $this->checksum($version))) {
                $changed[] = $version;
            }
        }

        return $changed;
    }

    /**
     * Angewandte Migrationen, deren Datei fehlt.
     *
     * @param list<string> $appliedVersions
     *
     * @return list<string>
     */
    public function missingFiles(array $appliedVersions): array
    {
        return array_values(array_diff($appliedVersions, array_keys($this->all())));
    }

    /**
     * Zerlegt eine Migrationsdatei in einzelne Anweisungen.
     *
     * Bewusst einfach gehalten: Getrennt wird an Semikolons am Zeilenende,
     * Kommentarzeilen und Leerzeilen entfallen. Das genuegt fuer reine
     * DDL-Skripte. Sollten je gespeicherte Prozeduren noetig werden, braucht es
     * eine echte Zerlegung mit DELIMITER-Behandlung.
     *
     * @return list<string>
     */
    public function statementsOf(string $version): array
    {
        $file = $this->all()[$version] ?? null;

        if ($file === null) {
            throw new \RuntimeException("Migration {$version} existiert nicht.");
        }

        $sql = (string) file_get_contents($file);

        // Kommentarzeilen entfernen
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
