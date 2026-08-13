<?php

declare(strict_types=1);

namespace LogViewer;

use SplFileObject;

/**
 * Liest die vom Bot geschriebenen Logdateien aus einem Verzeichnis.
 *
 * Die Klasse kapselt saemtliche Dateizugriffe des Log-Betrachters. Sie kennt das
 * Namensmuster praefix-YYYY-MM-DD.log und laesst ausschliesslich Dateien zu, die
 * diesem Muster entsprechen und unmittelbar im konfigurierten Verzeichnis liegen.
 */
final class LogReader
{
    private readonly string $logFolder;

    /**
     * @param string $logFolder Verzeichnis mit den Logdateien
     * @param string $logPrefix Dateinamenpraefix ohne Datumsanteil
     */
    public function __construct(
        string $logFolder,
        private readonly string $logPrefix,
    ) {
        // Einen fehlenden abschliessenden Trenner ergaenzen, damit die Konfiguration
        // beide Schreibweisen erlaubt.
        $this->logFolder = rtrim($logFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Liefert alle Logdateien des Verzeichnisses, neueste zuerst.
     *
     * Weil der Datumsanteil im Namen fest sortierbar ist, genuegt eine absteigende
     * Sortierung der Pfade; ein Blick auf das Aenderungsdatum ist nicht noetig.
     *
     * @return list<string> absolute Pfade
     */
    public function listFiles(): array
    {
        $pattern = $this->logFolder . $this->logPrefix . '-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log';
        $files = glob($pattern);

        if ($files === false || $files === []) {
            return [];
        }

        rsort($files);

        return array_values($files);
    }

    /**
     * Prueft, ob ein Dateiname dem erwarteten Muster entspricht.
     *
     * Bewusst streng: nur der reine Dateiname ohne Verzeichnisanteil wird
     * akzeptiert. Damit sind Pfadwechsel ueber "../" ausgeschlossen.
     */
    public function isValidFileName(string $name): bool
    {
        if ($name !== basename($name)) {
            return false;
        }

        return preg_match(
            '/^' . preg_quote($this->logPrefix, '/') . '-\d{4}-\d{2}-\d{2}\.log$/',
            $name,
        ) === 1;
    }

    /**
     * Loest einen Dateinamen in einen Pfad im Logverzeichnis auf.
     *
     * @return string|null null, wenn der Name nicht dem Muster entspricht
     */
    public function resolvePath(string $name): ?string
    {
        if (!$this->isValidFileName($name)) {
            return null;
        }

        return $this->logFolder . $name;
    }

    /**
     * Liefert die letzten Zeilen einer Datei; Leerzeilen entfallen.
     *
     * @return list<string>
     */
    public function tail(string $path, int $maxLines): array
    {
        if ($maxLines < 1 || !is_readable($path) || !is_file($path)) {
            return [];
        }

        $file = new SplFileObject($path);

        // An das Dateiende springen, um die Gesamtzahl der Zeilen zu bestimmen
        $file->seek(PHP_INT_MAX);
        $total = $file->key();

        // Bewusst ohne "+1": Bei einer mit Zeilenumbruch abgeschlossenen Datei ist die
        // letzte Position eine Leerzeile, die unten herausfaellt. So bleiben genau
        // $maxLines echte Zeilen uebrig.
        $lines = [];
        $file->seek(max(0, $total - $maxLines));

        while (!$file->eof()) {
            $line = rtrim((string) $file->current());
            if ($line !== '') {
                $lines[] = $line;
            }
            $file->next();
        }

        return $lines;
    }

    /**
     * Formt den Datumsanteil eines Dateinamens in die deutsche Schreibweise um.
     *
     * Passt der Name nicht zum Muster, wird der gefundene Rest unveraendert
     * zurueckgegeben - der Betrachter soll auch dann etwas anzeigen koennen.
     */
    public function formatDate(string $path): string
    {
        $base = basename($path, '.log');
        $date = substr($base, strlen($this->logPrefix) + 1);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1) {
            return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
        }

        return $date;
    }

    /**
     * Name der Logdatei des angegebenen Tages, standardmaessig heute.
     */
    public function fileNameForDay(?\DateTimeImmutable $day = null): string
    {
        $day ??= new \DateTimeImmutable('now');

        return $this->logPrefix . '-' . $day->format('Y-m-d') . '.log';
    }

    /**
     * Groesse einer Logdatei in Byte; 0, wenn sie nicht lesbar ist.
     */
    public function fileSize(string $path): int
    {
        if (!is_readable($path) || !is_file($path)) {
            return 0;
        }

        return (int) filesize($path);
    }
}
