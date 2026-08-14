<?php

declare(strict_types=1);

namespace Tests\bot;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use PegelBot\MessstellenController;
use PegelBot\TrendPolicy;
use PHPUnit\Framework\TestCase;
use Tests\support\FrozenClock;
use WSA\MeasurementApiInterface;

final class MessstellenControllerTest extends TestCase
{
    private function controller(): MessstellenController
    {
        return new MessstellenController(
            $this->createMock(Connection::class),
            $this->createMock(Logger::class),
            $this->createMock(MeasurementApiInterface::class),
            new FrozenClock('2026-08-14 14:00:00'),
            new TrendPolicy('Europe/Berlin'),
            1,
            'MAGDEBURG-STROMBRÜCKE',
            501,
            'uuid-1',
        );
    }

    /**
     * Normalfall: Der Zeitpunkt wird nach Ortszeit umgerechnet und als solche
     * gekennzeichnet. 13:30 UTC sind im Sommer 15:30 Ortszeit.
     */
    public function testFormatsUtcMomentAsLabelledLocalTime(): void
    {
        $moment = new DateTimeImmutable('2026-08-14 13:30:00', new DateTimeZone('UTC'));

        self::assertSame(
            '14.08.2026 15:30:00 Ortszeit',
            $this->controller()->formatLocalTime($moment),
        );
    }

    /**
     * Randfall Winterzeit: Der Versatz betraegt nur eine Stunde, die
     * Kennzeichnung bleibt dieselbe.
     */
    public function testFormatsWinterMomentWithSingleHourOffset(): void
    {
        $moment = new DateTimeImmutable('2026-01-15 13:30:00', new DateTimeZone('UTC'));

        self::assertSame(
            '15.01.2026 14:30:00 Ortszeit',
            $this->controller()->formatLocalTime($moment),
        );
    }

    /**
     * Randfall: Ein Zeitpunkt, der bereits in einer anderen Zeitzone vorliegt,
     * wird umgerechnet und nicht bloss beschriftet.
     */
    public function testConvertsMomentGivenInAnotherTimezone(): void
    {
        $moment = new DateTimeImmutable('2026-08-14 09:30:00', new DateTimeZone('America/New_York'));

        self::assertSame(
            '14.08.2026 15:30:00 Ortszeit',
            $this->controller()->formatLocalTime($moment),
        );
    }

    /**
     * Randfall: Der uebergebene Zeitpunkt darf nicht veraendert werden. Der
     * Controller haelt das letzte Messdatum als veraenderliches DateTime; eine
     * Umstellung der Zeitzone an dieser Stelle wuerde auf den zwischengespeicherten
     * Wert durchschlagen.
     */
    public function testLeavesTheGivenMomentUntouched(): void
    {
        $moment = new DateTime('2026-08-14 13:30:00', new DateTimeZone('UTC'));

        $this->controller()->formatLocalTime($moment);

        self::assertSame('UTC', $moment->getTimezone()->getName());
        self::assertSame('2026-08-14 13:30:00', $moment->format('Y-m-d H:i:s'));
    }
}
