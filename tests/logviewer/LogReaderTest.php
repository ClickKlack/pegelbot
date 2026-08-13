<?php

declare(strict_types=1);

namespace Tests\logviewer;

use DateTimeImmutable;
use LogViewer\LogReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    private string $logFolder;

    protected function setUp(): void
    {
        // Eigenes Verzeichnis je Test, damit sich die Faelle nicht beeinflussen
        $this->logFolder = sys_get_temp_dir() . '/logreader-test-' . uniqid('', true);
        mkdir($this->logFolder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logFolder . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logFolder);
    }

    private function reader(string $prefix = 'pegelbot'): LogReader
    {
        return new LogReader($this->logFolder, $prefix);
    }

    /**
     * Legt eine Logdatei mit durchnummerierten Zeilen an, abgeschlossen mit
     * Zeilenumbruch - so schreibt Monolog.
     */
    private function writeLog(string $name, int $lineCount): void
    {
        $lines = [];
        for ($i = 1; $i <= $lineCount; $i++) {
            $lines[] = "Zeile {$i}";
        }
        file_put_contents($this->logFolder . '/' . $name, implode("\n", $lines) . "\n");
    }

    // ------------------------------------------------------------------
    //  listFiles()
    // ------------------------------------------------------------------

    public function testListFilesReturnsEmptyArrayForEmptyFolder(): void
    {
        self::assertSame([], $this->reader()->listFiles());
    }

    public function testListFilesReturnsNewestFirst(): void
    {
        $this->writeLog('pegelbot-2026-08-01.log', 1);
        $this->writeLog('pegelbot-2026-08-13.log', 1);
        $this->writeLog('pegelbot-2026-08-07.log', 1);

        $names = array_map('basename', $this->reader()->listFiles());

        self::assertSame([
            'pegelbot-2026-08-13.log',
            'pegelbot-2026-08-07.log',
            'pegelbot-2026-08-01.log',
        ], $names);
    }

    public function testListFilesIgnoresForeignFiles(): void
    {
        $this->writeLog('pegelbot-2026-08-13.log', 1);
        // Weder das Praefix noch die Endung passen
        $this->writeLog('pegelbot.log', 1);
        $this->writeLog('andere-2026-08-13.log', 1);
        $this->writeLog('pegelbot-2026-08-13.txt', 1);

        $names = array_map('basename', $this->reader()->listFiles());

        self::assertSame(['pegelbot-2026-08-13.log'], $names);
    }

    public function testListFilesWorksWithoutTrailingSeparatorInConfiguration(): void
    {
        $this->writeLog('pegelbot-2026-08-13.log', 1);

        // Verzeichnis ohne abschliessenden Schraegstrich uebergeben
        $reader = new LogReader($this->logFolder, 'pegelbot');

        self::assertCount(1, $reader->listFiles());
    }

    // ------------------------------------------------------------------
    //  isValidFileName() - die Pfadpruefung des Betrachters
    // ------------------------------------------------------------------

    #[DataProvider('validFileNames')]
    public function testIsValidFileNameAcceptsMatchingNames(string $name): void
    {
        self::assertTrue($this->reader()->isValidFileName($name));
    }

    /** @return iterable<string, array{string}> */
    public static function validFileNames(): iterable
    {
        yield 'heutiges Datum'   => ['pegelbot-2026-08-13.log'];
        yield 'Jahreswechsel'    => ['pegelbot-2025-12-31.log'];
    }

    #[DataProvider('invalidFileNames')]
    public function testIsValidFileNameRejectsEverythingElse(string $name): void
    {
        self::assertFalse($this->reader()->isValidFileName($name));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFileNames(): iterable
    {
        yield 'Pfadwechsel nach oben'    => ['../../../etc/passwd'];
        yield 'Pfadwechsel mit Muster'   => ['../pegelbot-2026-08-13.log'];
        yield 'absoluter Pfad'           => ['/etc/passwd'];
        yield 'Unterverzeichnis'         => ['unterordner/pegelbot-2026-08-13.log'];
        yield 'falsches Praefix'         => ['andere-2026-08-13.log'];
        yield 'falsche Endung'           => ['pegelbot-2026-08-13.txt'];
        yield 'kein Datum'               => ['pegelbot.log'];
        yield 'unvollstaendiges Datum'   => ['pegelbot-2026-08.log'];
        yield 'Nullbyte angehaengt'      => ["pegelbot-2026-08-13.log\0.txt"];
        yield 'leer'                     => [''];
    }

    public function testResolvePathReturnsNullForInvalidName(): void
    {
        self::assertNull($this->reader()->resolvePath('../../etc/passwd'));
    }

    public function testResolvePathBuildsPathInsideLogFolder(): void
    {
        $path = $this->reader()->resolvePath('pegelbot-2026-08-13.log');

        self::assertSame($this->logFolder . '/pegelbot-2026-08-13.log', $path);
    }

    // ------------------------------------------------------------------
    //  tail()
    // ------------------------------------------------------------------

    public function testTailReturnsAllLinesWhenFileIsShorterThanLimit(): void
    {
        $this->writeLog('pegelbot-2026-08-13.log', 3);
        $path = $this->logFolder . '/pegelbot-2026-08-13.log';

        self::assertSame(
            ['Zeile 1', 'Zeile 2', 'Zeile 3'],
            $this->reader()->tail($path, 1000),
        );
    }

    public function testTailReturnsExactlyTheRequestedNumberOfLastLines(): void
    {
        $this->writeLog('pegelbot-2026-08-13.log', 100);
        $path = $this->logFolder . '/pegelbot-2026-08-13.log';

        $lines = $this->reader()->tail($path, 10);

        self::assertCount(10, $lines);
        self::assertSame('Zeile 91', $lines[0]);
        self::assertSame('Zeile 100', $lines[9]);
    }

    public function testTailDropsEmptyLines(): void
    {
        file_put_contents(
            $this->logFolder . '/pegelbot-2026-08-13.log',
            "erste\n\n\nzweite\n",
        );
        $path = $this->logFolder . '/pegelbot-2026-08-13.log';

        self::assertSame(['erste', 'zweite'], $this->reader()->tail($path, 1000));
    }

    public function testTailReturnsEmptyArrayForMissingFile(): void
    {
        self::assertSame(
            [],
            $this->reader()->tail($this->logFolder . '/gibt-es-nicht.log', 10),
        );
    }

    public function testTailReturnsEmptyArrayForDirectory(): void
    {
        self::assertSame([], $this->reader()->tail($this->logFolder, 10));
    }

    public function testTailReturnsEmptyArrayForNonPositiveLimit(): void
    {
        $this->writeLog('pegelbot-2026-08-13.log', 5);
        $path = $this->logFolder . '/pegelbot-2026-08-13.log';

        self::assertSame([], $this->reader()->tail($path, 0));
    }

    // ------------------------------------------------------------------
    //  formatDate()
    // ------------------------------------------------------------------

    public function testFormatDateConvertsToGermanNotation(): void
    {
        self::assertSame(
            '13.08.2026',
            $this->reader()->formatDate('/beliebig/pegelbot-2026-08-13.log'),
        );
    }

    public function testFormatDateReturnsRemainderForUnexpectedName(): void
    {
        self::assertSame(
            'ohne-datum',
            $this->reader()->formatDate('/beliebig/pegelbot-ohne-datum.log'),
        );
    }

    public function testFormatDateHonoursPrefixLength(): void
    {
        self::assertSame(
            '01.01.2026',
            $this->reader('mein-langes-praefix')
                ->formatDate('/beliebig/mein-langes-praefix-2026-01-01.log'),
        );
    }

    // ------------------------------------------------------------------
    //  fileNameForDay() und fileSize()
    // ------------------------------------------------------------------

    public function testFileNameForDayUsesGivenDay(): void
    {
        self::assertSame(
            'pegelbot-2026-08-13.log',
            $this->reader()->fileNameForDay(new DateTimeImmutable('2026-08-13 17:41:00')),
        );
    }

    public function testFileSizeReturnsZeroForMissingFile(): void
    {
        self::assertSame(0, $this->reader()->fileSize($this->logFolder . '/fehlt.log'));
    }

    public function testFileSizeReturnsByteCount(): void
    {
        file_put_contents($this->logFolder . '/pegelbot-2026-08-13.log', '12345');

        self::assertSame(
            5,
            $this->reader()->fileSize($this->logFolder . '/pegelbot-2026-08-13.log'),
        );
    }
}
