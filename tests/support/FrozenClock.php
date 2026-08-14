<?php

declare(strict_types=1);

namespace Tests\support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Uhr, die einen festen Zeitpunkt liefert.
 *
 * Nur fuer Tests. Ohne sie liesse sich zeitabhaengige Logik - Nachtsperre,
 * Abstandsregeln - nicht zuverlaessig pruefen, weil das Ergebnis von der
 * tatsaechlichen Uhrzeit des Testlaufs abhinge.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    /**
     * @param string $time     Zeitpunkt in beliebiger von DateTime lesbarer Form
     * @param string $timezone Zeitzone der Angabe
     */
    public function __construct(string $time, string $timezone = 'UTC')
    {
        $this->now = new DateTimeImmutable($time, new DateTimeZone($timezone));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Stellt die Uhr weiter, etwa "+2 hours".
     */
    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
