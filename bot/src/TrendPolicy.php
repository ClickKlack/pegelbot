<?php

declare(strict_types=1);

namespace PegelBot;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Entscheidet, ob eine Ganglinie verschickt werden soll.
 *
 * Zwei Regeln, beide bislang als eingestreute Zahlen im Ablauf verteilt:
 *
 *   Nachtsperre  - zwischen Abend und Morgen wird nichts verschickt.
 *   Ausloeser    - entweder der Wasserstand hat sich seit der letzten Grafik
 *                  deutlich bewegt und diese liegt mindestens einen Tag zurueck,
 *                  oder sie liegt lange genug zurueck, dass ohnehin eine faellig
 *                  ist.
 *
 * Die Klasse haelt keinen Zustand und greift auf nichts zu. Dadurch ist sie ohne
 * Datenbank und ohne Netz pruefbar - und mit ihr die Zeitlogik, die im
 * MessstellenController bislang unerreichbar war.
 */
final class TrendPolicy
{
    private readonly DateTimeZone $timezone;

    /**
     * @param string $timezone       Zeitzone, in der die Nachtsperre gilt
     * @param int    $quietFromHour  ab dieser Stunde wird nicht mehr verschickt
     * @param int    $quietUntilHour bis zu dieser Stunde wird nicht verschickt
     * @param int    $minimumSpread  noetige Schwankung in Zentimetern
     * @param int    $minimumDays    Mindestabstand in Tagen bei Schwankung
     * @param int    $maximumDays    Abstand, ab dem ohne Schwankung verschickt wird
     */
    public function __construct(
        string $timezone = 'UTC',
        private readonly int $quietFromHour = 22,
        private readonly int $quietUntilHour = 6,
        private readonly int $minimumSpread = 50,
        private readonly int $minimumDays = 1,
        private readonly int $maximumDays = 7,
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * Liegt der Zeitpunkt in der Nachtsperre?
     *
     * Die Grenzen werden in der konfigurierten Zeitzone ausgewertet. Genau hier
     * liegt Befund B3: Ausgewertet wird die UTC-Stunde, obwohl 6 und 22 als
     * Ortszeit gemeint sind. Die Behebung folgt gesondert.
     */
    public function isQuietTime(DateTimeInterface $now): bool
    {
        $hour = (int) DateTimeImmutable::createFromInterface($now)
            ->setTimezone($this->timezone)
            ->format('G');

        return $hour < $this->quietUntilHour || $hour > $this->quietFromHour;
    }

    /**
     * Soll jetzt eine Ganglinie verschickt werden?
     *
     * @param DateTimeInterface $now        aktueller Zeitpunkt
     * @param DateTimeInterface $lastSent   Zeitpunkt der letzten Ganglinie
     * @param int|null          $minValue   kleinster Messwert seit dann
     * @param int|null          $maxValue   groesster Messwert seit dann
     */
    public function shouldSend(
        DateTimeInterface $now,
        DateTimeInterface $lastSent,
        ?int $minValue,
        ?int $maxValue,
    ): bool {
        if ($this->isQuietTime($now)) {
            return false;
        }

        $days = $this->daysSince($now, $lastSent);

        // Ohne Messwerte gibt es keine Schwankung, aber die lange Frist greift
        if ($minValue === null || $maxValue === null) {
            return $days >= $this->maximumDays;
        }

        $spread = abs($maxValue - $minValue);

        return ($spread >= $this->minimumSpread && $days >= $this->minimumDays)
            || $days >= $this->maximumDays;
    }

    /**
     * Volle Tage zwischen zwei Zeitpunkten, Reihenfolge ohne Belang.
     */
    public function daysSince(DateTimeInterface $now, DateTimeInterface $lastSent): int
    {
        return (int) DateTimeImmutable::createFromInterface($now)
            ->diff(DateTimeImmutable::createFromInterface($lastSent), true)
            ->days;
    }

    /**
     * Zeitzone, in der die Nachtsperre ausgewertet wird - fuer Protokollausgaben.
     */
    public function timezoneName(): string
    {
        return $this->timezone->getName();
    }
}
