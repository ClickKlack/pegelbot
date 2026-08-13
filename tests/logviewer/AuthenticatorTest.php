<?php

declare(strict_types=1);

namespace Tests\logviewer;

use LogViewer\Authenticator;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    private const PASSWORD = 'ein-hinreichend-langes-kennwort';

    /** Fester Bezugszeitpunkt, damit die Tests nicht von der Uhr abhaengen */
    private const NOW = 1_786_000_000;

    private string $stateFolder;
    private string $hash;

    protected function setUp(): void
    {
        $this->stateFolder = sys_get_temp_dir() . '/auth-test-' . uniqid('', true);
        mkdir($this->stateFolder);

        // Guenstige Kostenstufe, damit die Testsuite schnell bleibt
        $this->hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateFolder . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->stateFolder);
    }

    private function auth(?string $hash = null, int $maxAttempts = 5, int $lockout = 900): Authenticator
    {
        return new Authenticator($hash ?? $this->hash, $this->stateFolder, $maxAttempts, $lockout);
    }

    // ------------------------------------------------------------------
    //  Einrichtung
    // ------------------------------------------------------------------

    public function testIsConfiguredWithProperHash(): void
    {
        self::assertTrue($this->auth()->isConfigured());
    }

    public function testIsNotConfiguredWithEmptyHash(): void
    {
        self::assertFalse($this->auth('')->isConfigured());
    }

    public function testIsNotConfiguredWithPlaintextPlaceholder(): void
    {
        // Genau der Fall, der frueher im Quelltext stand
        self::assertFalse($this->auth('BITTE-NEUES-KENNWORT-EINTRAGEN')->isConfigured());
    }

    public function testUnconfiguredAuthenticatorRejectsEveryPassword(): void
    {
        $auth = $this->auth('');

        self::assertFalse($auth->verify(''));
        self::assertFalse($auth->verify('irgendwas'));
    }

    // ------------------------------------------------------------------
    //  Kennwortpruefung
    // ------------------------------------------------------------------

    public function testVerifyAcceptsCorrectPassword(): void
    {
        self::assertTrue($this->auth()->verify(self::PASSWORD));
    }

    public function testVerifyRejectsWrongPassword(): void
    {
        self::assertFalse($this->auth()->verify('falsch'));
    }

    public function testVerifyRejectsEmptyPassword(): void
    {
        self::assertFalse($this->auth()->verify(''));
    }

    public function testVerifyIsCaseSensitive(): void
    {
        self::assertFalse($this->auth()->verify(strtoupper(self::PASSWORD)));
    }

    // ------------------------------------------------------------------
    //  Kennung des Aufrufers
    // ------------------------------------------------------------------

    public function testClientKeyIsStableForSameInput(): void
    {
        $auth = $this->auth();

        self::assertSame(
            $auth->clientKey('192.0.2.1', 'Firefox'),
            $auth->clientKey('192.0.2.1', 'Firefox'),
        );
    }

    public function testClientKeyDiffersByAddress(): void
    {
        $auth = $this->auth();

        self::assertNotSame(
            $auth->clientKey('192.0.2.1', 'Firefox'),
            $auth->clientKey('192.0.2.2', 'Firefox'),
        );
    }

    public function testClientKeyContainsNoPlaintextAddress(): void
    {
        // Im Zustandsverzeichnis sollen keine IP-Adressen lesbar sein
        self::assertStringNotContainsString('192.0.2.1', $this->auth()->clientKey('192.0.2.1'));
    }

    // ------------------------------------------------------------------
    //  Versuchsbegrenzung
    // ------------------------------------------------------------------

    public function testNoLockoutInitially(): void
    {
        self::assertFalse($this->auth()->isLockedOut('abc', self::NOW));
    }

    public function testNoLockoutBelowThreshold(): void
    {
        $auth = $this->auth(maxAttempts: 5);

        for ($i = 0; $i < 4; $i++) {
            $auth->registerFailure('abc', self::NOW);
        }

        self::assertFalse($auth->isLockedOut('abc', self::NOW));
    }

    public function testLockoutAtThreshold(): void
    {
        $auth = $this->auth(maxAttempts: 5);

        for ($i = 0; $i < 5; $i++) {
            $auth->registerFailure('abc', self::NOW);
        }

        self::assertTrue($auth->isLockedOut('abc', self::NOW));
    }

    public function testLockoutExpiresAfterConfiguredTime(): void
    {
        $auth = $this->auth(maxAttempts: 3, lockout: 900);

        for ($i = 0; $i < 3; $i++) {
            $auth->registerFailure('abc', self::NOW);
        }

        self::assertTrue($auth->isLockedOut('abc', self::NOW + 899));
        self::assertFalse($auth->isLockedOut('abc', self::NOW + 900));
    }

    public function testRemainingLockoutCountsDown(): void
    {
        $auth = $this->auth(maxAttempts: 1, lockout: 600);
        $auth->registerFailure('abc', self::NOW);

        self::assertSame(600, $auth->remainingLockout('abc', self::NOW));
        self::assertSame(100, $auth->remainingLockout('abc', self::NOW + 500));
        self::assertSame(0, $auth->remainingLockout('abc', self::NOW + 600));
    }

    public function testLockoutIsPerClient(): void
    {
        $auth = $this->auth(maxAttempts: 2);

        $auth->registerFailure('client-a', self::NOW);
        $auth->registerFailure('client-a', self::NOW);

        self::assertTrue($auth->isLockedOut('client-a', self::NOW));
        self::assertFalse($auth->isLockedOut('client-b', self::NOW));
    }

    public function testCountingRestartsAfterExpiredLockout(): void
    {
        $auth = $this->auth(maxAttempts: 2, lockout: 900);

        $auth->registerFailure('abc', self::NOW);
        $auth->registerFailure('abc', self::NOW);
        self::assertTrue($auth->isLockedOut('abc', self::NOW));

        // Nach Ablauf ein einzelner neuer Fehlversuch darf nicht sofort sperren
        $auth->registerFailure('abc', self::NOW + 1000);
        self::assertFalse($auth->isLockedOut('abc', self::NOW + 1000));
    }

    public function testSuccessfulLoginClearsFailures(): void
    {
        $auth = $this->auth(maxAttempts: 3);

        $auth->registerFailure('abc', self::NOW);
        $auth->registerFailure('abc', self::NOW);
        $auth->clearFailures('abc');

        // Nach dem Zuruecksetzen darf ein weiterer Fehlversuch nicht sperren
        $auth->registerFailure('abc', self::NOW);
        self::assertFalse($auth->isLockedOut('abc', self::NOW));
    }

    /**
     * Die Sperre darf nicht dadurch zu umgehen sein, dass der Aufrufer sein
     * Sitzungsplaetzchen verwirft - deshalb liegt der Zaehler auf der Platte.
     */
    public function testLockoutSurvivesNewAuthenticatorInstance(): void
    {
        $first = $this->auth(maxAttempts: 2);
        $first->registerFailure('abc', self::NOW);
        $first->registerFailure('abc', self::NOW);

        $second = $this->auth(maxAttempts: 2);

        self::assertTrue($second->isLockedOut('abc', self::NOW));
    }

    public function testStateFolderIsCreatedOnDemand(): void
    {
        $folder = $this->stateFolder . '/tiefer/verschachtelt';
        $auth = new Authenticator($this->hash, $folder, 2, 900);

        $auth->registerFailure('abc', self::NOW);

        self::assertDirectoryExists($folder);

        // Aufraeumen, damit tearDown() das Basisverzeichnis entfernen kann
        array_map('unlink', glob($folder . '/*') ?: []);
        rmdir($folder);
        rmdir($this->stateFolder . '/tiefer');
    }

    public function testPurgeExpiredRemovesOldCountersOnly(): void
    {
        $auth = $this->auth(maxAttempts: 2, lockout: 900);

        $auth->registerFailure('alt', self::NOW);
        $auth->registerFailure('neu', self::NOW + 5000);

        $auth->purgeExpired(self::NOW + 5000);

        self::assertFalse($auth->isLockedOut('alt', self::NOW + 5000));
        self::assertCount(1, glob($this->stateFolder . '/*.json') ?: []);
    }

    public function testCorruptStateFileIsTreatedAsNoFailures(): void
    {
        $auth = $this->auth();
        $key = 'abc';

        file_put_contents($this->stateFolder . '/' . $key . '.json', 'kein gueltiges JSON');

        self::assertFalse($auth->isLockedOut($key, self::NOW));
    }
}
