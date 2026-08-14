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
    //  Nachtsperre - Stand vor Behebung von B3
    // ------------------------------------------------------------------

    #[DataProvider('quietHoursInUtc')]
    public function testQuietTimeIsEvaluatedInUtc(string $time, bool $expected): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertSame($expected, $policy->isQuietTime($this->at($time)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function quietHoursInUtc(): iterable
    {
        yield 'Mitternacht'  => ['2026-08-14 00:00:00', true];
        yield '5 Uhr'        => ['2026-08-14 05:59:00', true];
        yield '6 Uhr'        => ['2026-08-14 06:00:00', false];
        yield 'Mittag'       => ['2026-08-14 12:00:00', false];
        yield '22 Uhr'       => ['2026-08-14 22:00:00', false];
        yield '23 Uhr'       => ['2026-08-14 23:00:00', true];
    }

    /**
     * Haelt Befund B3 fest: Die Grenzen 6 und 22 sind als Ortszeit gemeint,
     * ausgewertet wird aber die UTC-Stunde. Diese Tests werden mit der Behebung
     * umgedreht.
     */
    public function testCurrentlyNotQuietAtElevenPmLocalTime(): void
    {
        $policy = new TrendPolicy('UTC');

        // 23 Uhr Ortszeit im Sommer entspricht 21 Uhr UTC
        self::assertFalse($policy->isQuietTime($this->at('2026-08-14 23:00:00', 'Europe/Berlin')));
    }

    public function testCurrentlyQuietAtSevenAmLocalTime(): void
    {
        $policy = new TrendPolicy('UTC');

        // 7 Uhr Ortszeit im Sommer entspricht 5 Uhr UTC
        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 07:00:00', 'Europe/Berlin')));
    }

    /**
     * Zweiter Teil von B3: Der Vergleich lautet "> 22" statt ">=". Die Stunde 22
     * ist dadurch nicht gesperrt, obwohl der Kommentar "22-6 Uhr" sagt.
     */
    public function testQuietCurrentlyStartsAtElevenNotTen(): void
    {
        $policy = new TrendPolicy('UTC');

        self::assertFalse($policy->isQuietTime($this->at('2026-08-14 22:30:00')));
        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 23:30:00')));
    }

    public function testTimezoneIsConfigurable(): void
    {
        self::assertTrue((new TrendPolicy('Europe/Berlin'))
            ->isQuietTime($this->at('2026-08-14 23:30:00', 'Europe/Berlin')));
    }

    public function testQuietHoursAreConfigurable(): void
    {
        $policy = new TrendPolicy('UTC', quietFromHour: 20, quietUntilHour: 8);

        self::assertTrue($policy->isQuietTime($this->at('2026-08-14 21:00:00')));
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
