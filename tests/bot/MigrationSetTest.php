<?php

declare(strict_types=1);

namespace Tests\bot;

use PegelBot\MigrationSet;
use PHPUnit\Framework\TestCase;

final class MigrationSetTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/migrationset-test-' . uniqid('', true);
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->folder);
    }

    private function removeRecursively(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeRecursively($entry) : unlink($entry);
        }
        rmdir($path);
    }

    private function writeMigration(string $name, string $sql = 'SELECT 1;'): void
    {
        file_put_contents($this->folder . '/' . $name, $sql);
    }

    private function set(): MigrationSet
    {
        return new MigrationSet($this->folder);
    }

    // ------------------------------------------------------------------
    //  Finden und Sortieren
    // ------------------------------------------------------------------

    public function testEmptyFolderHasNoMigrations(): void
    {
        self::assertSame([], $this->set()->all());
    }

    public function testMigrationsAreSortedByVersion(): void
    {
        $this->writeMigration('003_drittens.sql');
        $this->writeMigration('001_erstens.sql');
        $this->writeMigration('002_zweitens.sql');

        self::assertSame(['001', '002', '003'], array_keys($this->set()->all()));
    }

    public function testBaselineComesFirst(): void
    {
        $this->writeMigration('001_erstens.sql');
        $this->writeMigration('000_baseline_schema.sql');

        self::assertSame(['000', '001'], array_keys($this->set()->all()));
    }

    public function testFilesWithoutVersionPrefixAreIgnored(): void
    {
        $this->writeMigration('001_gueltig.sql');
        $this->writeMigration('irgendwas.sql');
        $this->writeMigration('01_zu_kurz.sql');
        $this->writeMigration('001_falsche_endung.txt');

        self::assertSame(['001'], array_keys($this->set()->all()));
    }

    /**
     * In migrations/legacy/ liegen historische Skripte, die bereits in der
     * Baseline enthalten sind. Sie duerfen nicht erneut angewandt werden.
     */
    public function testSubfoldersAreNotSearched(): void
    {
        $this->writeMigration('001_gueltig.sql');
        mkdir($this->folder . '/legacy');
        file_put_contents($this->folder . '/legacy/002_alt.sql', 'SELECT 1;');

        self::assertSame(['001'], array_keys($this->set()->all()));
    }

    public function testWorksWithoutTrailingSeparator(): void
    {
        $this->writeMigration('001_erstens.sql');

        self::assertCount(1, (new MigrationSet($this->folder))->all());
    }

    // ------------------------------------------------------------------
    //  Beschreibung
    // ------------------------------------------------------------------

    public function testDescribeTurnsFileNameIntoText(): void
    {
        $this->writeMigration('001_zeichensatz_utf8mb4.sql');

        self::assertSame('zeichensatz utf8mb4', $this->set()->describe('001'));
    }

    public function testDescribeUnknownVersion(): void
    {
        self::assertSame('(unbekannt)', $this->set()->describe('099'));
    }

    // ------------------------------------------------------------------
    //  Ausstehende Migrationen
    // ------------------------------------------------------------------

    public function testAllPendingWhenNothingApplied(): void
    {
        $this->writeMigration('001_erstens.sql');
        $this->writeMigration('002_zweitens.sql');

        self::assertSame(['001', '002'], array_keys($this->set()->pending([])));
    }

    public function testAppliedMigrationsAreNotPending(): void
    {
        $this->writeMigration('001_erstens.sql');
        $this->writeMigration('002_zweitens.sql');
        $this->writeMigration('003_drittens.sql');

        self::assertSame(['003'], array_keys($this->set()->pending(['001', '002'])));
    }

    public function testNothingPendingWhenAllApplied(): void
    {
        $this->writeMigration('001_erstens.sql');

        self::assertSame([], $this->set()->pending(['001']));
    }

    // ------------------------------------------------------------------
    //  Unversehrtheit
    // ------------------------------------------------------------------

    public function testChecksumIsStable(): void
    {
        $this->writeMigration('001_erstens.sql', 'ALTER TABLE a ADD b INT;');
        $set = $this->set();

        self::assertSame($set->checksum('001'), $set->checksum('001'));
    }

    public function testChecksumChangesWithContent(): void
    {
        $this->writeMigration('001_erstens.sql', 'ALTER TABLE a ADD b INT;');
        $before = $this->set()->checksum('001');

        $this->writeMigration('001_erstens.sql', 'ALTER TABLE a ADD c INT;');

        self::assertNotSame($before, $this->set()->checksum('001'));
    }

    /**
     * Die Konvention verlangt, dass angewandte Migrationen unveraendert bleiben.
     * Der Pruefwert macht daraus eine Zusicherung.
     */
    public function testChangedMigrationIsDetected(): void
    {
        $this->writeMigration('001_erstens.sql', 'ALTER TABLE a ADD b INT;');
        $recorded = ['001' => $this->set()->checksum('001')];

        $this->writeMigration('001_erstens.sql', 'DROP TABLE a;');

        self::assertSame(['001'], $this->set()->changedSinceApplied($recorded));
    }

    public function testUnchangedMigrationIsNotReported(): void
    {
        $this->writeMigration('001_erstens.sql', 'ALTER TABLE a ADD b INT;');
        $recorded = ['001' => $this->set()->checksum('001')];

        self::assertSame([], $this->set()->changedSinceApplied($recorded));
    }

    public function testMissingFileIsDetected(): void
    {
        $this->writeMigration('001_erstens.sql');

        self::assertSame(['002'], $this->set()->missingFiles(['001', '002']));
    }

    public function testNoMissingFilesWhenAllPresent(): void
    {
        $this->writeMigration('001_erstens.sql');

        self::assertSame([], $this->set()->missingFiles(['001']));
    }

    public function testChecksumOfUnknownVersionThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->set()->checksum('099');
    }

    // ------------------------------------------------------------------
    //  Zerlegung in Anweisungen
    // ------------------------------------------------------------------

    public function testSingleStatement(): void
    {
        $this->writeMigration('001_erstens.sql', 'ALTER TABLE a ADD b INT;');

        self::assertSame(['ALTER TABLE a ADD b INT'], $this->set()->statementsOf('001'));
    }

    public function testSeveralStatements(): void
    {
        $this->writeMigration('001_erstens.sql', "ALTER TABLE a ADD b INT;\nALTER TABLE c ADD d INT;");

        self::assertSame(
            ['ALTER TABLE a ADD b INT', 'ALTER TABLE c ADD d INT'],
            $this->set()->statementsOf('001'),
        );
    }

    public function testCommentsAndBlankLinesAreDropped(): void
    {
        $this->writeMigration('001_erstens.sql', <<<SQL
            -- Ein Kommentar
            -- noch einer

            ALTER TABLE a ADD b INT;

            -- Abschliessender Kommentar
            SQL);

        self::assertSame(['ALTER TABLE a ADD b INT'], $this->set()->statementsOf('001'));
    }

    public function testMultilineStatementStaysTogether(): void
    {
        $this->writeMigration('001_erstens.sql', "ALTER TABLE a\n  ADD b INT,\n  ALGORITHM=INSTANT;");

        $statements = $this->set()->statementsOf('001');

        self::assertCount(1, $statements);
        self::assertStringContainsString('ALGORITHM=INSTANT', $statements[0]);
    }

    public function testTrailingSemicolonDoesNotCreateEmptyStatement(): void
    {
        $this->writeMigration('001_erstens.sql', "ALTER TABLE a ADD b INT;\n\n");

        self::assertCount(1, $this->set()->statementsOf('001'));
    }

    public function testFileWithOnlyCommentsHasNoStatements(): void
    {
        $this->writeMigration('001_erstens.sql', "-- nur ein Kommentar\n-- und noch einer\n");

        self::assertSame([], $this->set()->statementsOf('001'));
    }
}
