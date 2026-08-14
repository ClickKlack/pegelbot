<?php

declare(strict_types=1);

namespace Tests\bot;

use DateTimeImmutable;
use DateTimeZone;
use PegelBot\TrendPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\support\FrozenClock;

final class TrendPolicyTest extends TestCase
{
    private function at(string $time, string $timezone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone($timezone));
    }

    // ------------------------------------------------------------------
    //  Nachtsperre - gesperrt ist 22:00 bis 05:59 Ortszeit
    // ------------------------------------------------------------------

    #[DataProvider('quietHoursLocalTime')]
    public function testQuietWindowInLocalTime(string $time, bool $expected): void
    {
        $policy = new TrendPolicy('Europe/Berlin');

        self::assertSame($expected, $policy->isQuietTime($this->at($time, 'Europe/Berlin')));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function quietHoursLocalTime(): iterable
    {
        yield 'Mitternacht'          => ['2026-08-14 00:00:00', true];
        yield '5:59 Uhr'             => ['2026-08-14 05:59:00', true];
        yield '6 Uhr, Sperre endet'  => ['2026-08-14 06:00:00', false];
        yield 'Mittag'               => ['2026-08-14 12:00:00', false];
        yield '21:59 Uhr'            => ['2026-08-14 21:59:00', false];
        yield '22 Uhr, Sperre beginnt' => ['2026-08-14 22:00:00', true];
        yield '23 Uhr'               => ['2026-08-14 23:00:00', true];
    }

    /**
     * Befund B3, erster Teil: Die Grenzen sind als Ortszeit gemeint, ausgewertet
     * wurde aber die UTC-Stunde. In der Sommerzeit liegen dazwischen zwei
     * Stunden.
     */
    public function testQuietAtElevenPmLocalTime(): void
    {
        $policy = new TrendPolicy('Europe/Berlin');

        // 23 Uhr Ortszeit im Sommer entspricht 21 Uhr UTC - frueher Sendezeit
        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 23:00:00', 'Europe/Berlin')));
    }

    public function testNotQuietAtSevenAmLocalTime(): void
    {
        $policy = new TrendPolicy('Europe/Berlin');

        // 7 Uhr Ortszeit im Sommer entspricht 5 Uhr UTC - frueher Nachtsperre
        self::assertFalse($policy->isQuietTime($this->at('2026-08-14 07:00:00', 'Europe/Berlin')));
    }

    /**
     * Befund B3, zweiter Teil: Der Vergleich lautete "> 22" statt ">= 22".
     * Die Stunde 22 war dadurch nicht gesperrt, obwohl der Kommentar seit jeher
     * "22-6 Uhr" sagte.
     */
    public function testQuietStartsAtTenPmSharp(): void
    {
        $policy = new TrendPolicy('Europe/Berlin');

        self::assertFalse($policy->isQuietTime($this->at('2026-08-14 21:59:59', 'Europe/Berlin')));
        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 22:00:00', 'Europe/Berlin')));
    }

    public function testQuietTimeHonoursWinterTimeOffset(): void
    {
        $policy = new TrendPolicy('Europe/Berlin');

        // Im Winter betraegt der Versatz nur eine Stunde
        self::assertTrue($policy->isQuietTime($this->at('2026-01-15 22:30:00', 'Europe/Berlin')));
        self::assertFalse($policy->isQuietTime($this->at('2026-01-15 12:00:00', 'Europe/Berlin')));
    }

    /**
     * Ein in UTC angegebener Zeitpunkt muss dasselbe Ergebnis liefern wie
     * derselbe Moment in Ortszeit - die Umrechnung passiert in der Klasse.
     */
    public function testUtcInputIsConvertedBeforeComparison(): void
    {
        $policy = new TrendPolicy('Europe/Berlin');

        // 21:00 UTC im Sommer ist 23:00 Ortszeit
        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 21:00:00')));
        // 05:00 UTC im Sommer ist 07:00 Ortszeit
        self::assertFalse($policy->isQuietTime($this->at('2026-08-14 05:00:00')));
    }

    public function testQuietHoursAreConfigurable(): void
    {
        $policy = new TrendPolicy('UTC', quietFromHour: 20, quietUntilHour: 8);

        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 20:00:00')));
        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 07:00:00')));
        self::assertFalse($policy->isQuietTime($this->at('2026-08-14 12:00:00')));
    }

    // ------------------------------------------------------------------
    //  Ausloeseregel
    // ------------------------------------------------------------------

    public function testNothingIsSentDuringQuietHours(): void
    {
        $policy = new TrendPolicy('UTC');

        // Schwankung und Abstand wuerden ausreichen, die Sperre wiegt schwerer
        self::assertFalse($policy->shouldSend(
            $this->at('2026-08-14 03:00:00'),
            $this->at('2026-08-01 12:00:00'),
            10,
            200,
        ));
    }

    public function testSendsWhenSpreadAndOneDayReached(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertTrue($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-13 11:00:00'),
            100,
            150,
        ));
    }

    public function testSpreadBelowThresholdIsNotEnough(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertFalse($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-13 11:00:00'),
            100,
            149,
        ));
    }

    public function testSpreadExactlyAtThresholdIsEnough(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertTrue($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-13 11:00:00'),
            100,
            150,
        ));
    }

    public function testSpreadWithinTheSameDayIsNotEnough(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertFalse($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-14 06:00:00'),
            100,
            200,
        ));
    }

    public function testSendsAfterLongPauseWithoutAnySpread(): void
    {
        $policy = new TrendPolicy('UTC');

        // Sieben Tage genuegen auch ohne Bewegung
        self::assertTrue($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-07 11:00:00'),
            100,
            100,
        ));
    }

    public function testSixDaysWithoutSpreadIsNotEnough(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertFalse($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-08 13:00:00'),
            100,
            100,
        ));
    }

    /**
     * Kommt bei einer Messstelle vor, die noch keinen einzigen Messwert hat.
     * Die Schwankungsregel greift dann nicht, die lange Frist schon.
     */
    public function testMissingValuesFallBackToTheLongInterval(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertFalse($policy->shouldSend($this->at('2026-08-14 12:00:00'), $this->at('2026-08-13 11:00:00'), null, null));
        self::assertTrue($policy->shouldSend($this->at('2026-08-14 12:00:00'), $this->at('2026-08-01 11:00:00'), null, null));
    }

    public function testThresholdsAreConfigurable(): void
    {
        $policy = new TrendPolicy('UTC', minimumSpread: 10, maximumDays: 3);

        self::assertTrue($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-13 11:00:00'),
            100,
            110,
        ));

        self::assertTrue($policy->shouldSend(
            $this->at('2026-08-14 12:00:00'),
            $this->at('2026-08-11 11:00:00'),
            100,
            100,
        ));
    }

    // ------------------------------------------------------------------
    //  Abstand in Tagen
    // ------------------------------------------------------------------

    public function testDaysSinceCountsFullDays(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertSame(0, $policy->daysSince($this->at('2026-08-14 12:00:00'), $this->at('2026-08-13 13:00:00')));
        self::assertSame(1, $policy->daysSince($this->at('2026-08-14 12:00:00'), $this->at('2026-08-13 11:00:00')));
        self::assertSame(7, $policy->daysSince($this->at('2026-08-14 12:00:00'), $this->at('2026-08-07 11:00:00')));
    }

    public function testDaysSinceIgnoresOrder(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertSame(
            $policy->daysSince($this->at('2026-08-14 12:00:00'), $this->at('2026-08-07 12:00:00')),
            $policy->daysSince($this->at('2026-08-07 12:00:00'), $this->at('2026-08-14 12:00:00')),
        );
    }

    // ------------------------------------------------------------------
    //  Zusammenspiel mit der Uhr
    // ------------------------------------------------------------------

    public function testWorksWithAnInjectedClock(): void
    {
        $clock  = new FrozenClock('2026-08-14 03:00:00');
        $policy = new TrendPolicy('UTC');

        self::assertTrue($policy->isQuietTime($clock->now()));

        $clock->advance('+9 hours');

        self::assertFalse($policy->isQuietTime($clock->now()));
    }
}
