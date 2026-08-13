<?php

declare(strict_types=1);

namespace Tests\bot\wsa;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSA\Measurement;

final class MeasurementTest extends TestCase
{
    // ------------------------------------------------------------------
    //  Messwert
    // ------------------------------------------------------------------

    /**
     * PEGELONLINE liefert die Werte durchweg als Gleitkommazahl, auch wenn kein
     * Nachkommaanteil vorhanden ist. Genau dieser Fall liess einen Botlauf
     * abstuerzen, nachdem strikte Typen eingefuehrt worden waren.
     */
    public function testAcceptsFloatValueFromApi(): void
    {
        $measurement = new Measurement('2026-08-13T06:00:00+02:00', 38.0);

        self::assertSame(38, $measurement->getValue());
    }

    public function testAcceptsIntegerValue(): void
    {
        self::assertSame(38, (new Measurement('2026-08-13T06:00:00+02:00', 38))->getValue());
    }

    public function testAcceptsNegativeValue(): void
    {
        // Manche Pegel koennen Werte unterhalb des Bezugsniveaus melden
        self::assertSame(-12, (new Measurement('2026-08-13T06:00:00+02:00', -12.0))->getValue());
    }

    /**
     * Nachkommaanteile kommen in den geprueften Daten nicht vor. Falls doch,
     * soll gerundet und nicht abgeschnitten werden.
     */
    #[DataProvider('fractionalValues')]
    public function testRoundsFractionalValues(float $given, int $expected): void
    {
        self::assertSame($expected, (new Measurement('2026-08-13T06:00:00+02:00', $given))->getValue());
    }

    /** @return iterable<string, array{float, int}> */
    public static function fractionalValues(): iterable
    {
        yield 'abrunden'          => [37.4, 37];
        yield 'aufrunden'         => [37.6, 38];
        yield 'genau die Haelfte' => [37.5, 38];
        yield 'negativ abrunden'  => [-37.6, -38];
    }

    // ------------------------------------------------------------------
    //  Zeitstempel
    // ------------------------------------------------------------------

    public function testTimestampIsNormalisedToUtc(): void
    {
        $measurement = new Measurement('2026-08-13T06:00:00+02:00', 38.0);

        self::assertSame('UTC', $measurement->getTimestamp()->getTimezone()->getName());
        self::assertSame('2026-08-13 04:00:00', $measurement->getTimestamp()->format('Y-m-d H:i:s'));
    }

    public function testTimestampAlreadyInUtcStaysUnchanged(): void
    {
        $measurement = new Measurement('2026-08-13T06:00:00+00:00', 38.0);

        self::assertSame('2026-08-13 06:00:00', $measurement->getTimestamp()->format('Y-m-d H:i:s'));
    }

    public function testTimestampHandlesWinterTimeOffset(): void
    {
        // Im Winter betraegt der Versatz nur eine Stunde
        $measurement = new Measurement('2026-01-15T06:00:00+01:00', 38.0);

        self::assertSame('2026-01-15 05:00:00', $measurement->getTimestamp()->format('Y-m-d H:i:s'));
    }
}
