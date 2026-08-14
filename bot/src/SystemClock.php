<?php

declare(strict_types=1);

namespace PegelBot;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Die tatsaechliche Uhr des Systems.
 *
 * Existiert, damit zeitabhaengige Fachlogik nicht selbst "jetzt" bestimmt und
 * dadurch in Tests festhaltbar wird. Produktiv wird diese Umsetzung verwendet,
 * in Tests eine, die einen festen Zeitpunkt liefert.
 *
 * Liefert bewusst UTC: Zeitstempel werden im gesamten Bot in UTC gehalten und
 * erst bei der Ausgabe umgerechnet.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
