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
     * @param int    $quietFromHour  ab dieser Stunde einschliesslich gesperrt
     * @param int    $quietUntilHour bis zu dieser Stunde ausschliesslich gesperrt
     * @param int    $minimumSpread  noetige Schwankung in Zentimetern
     * @param int    $minimumDays    Mindestabstand in Tagen bei Schwankung
     * @param int    $maximumDays    Abstand, ab dem ohne Schwankung verschickt wird
     */
    public function __construct(
        string $timezone = 'Europe/Berlin',
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
     * Das Fenster ist halboffen: ab quietFromHour einschliesslich bis
     * quietUntilHour ausschliesslich. Mit den Vorgabewerten also von 22:00:00
     * bis 05:59:59 gesperrt, von 06:00 bis 21:59 wird verschickt.
     *
     * Hier lag Befund B3, und zwar doppelt:
     *
     *   - Die Stunde wurde in UTC ausgewertet, obwohl die Grenzen als Ortszeit
     *     gemeint sind.
     *   - Der Vergleich lautete "> quietFromHour" statt ">=". Die Stunde 22 war
     *     damit nicht gesperrt, obwohl der Kommentar seit jeher "22-6 Uhr" sagte.
     *
     * Beides zusammen ergab real eine Sperre von 01:00 bis 07:59 Ortszeit.
     */
    public function isQuietTime(DateTimeInterface $now): bool
    {
        $hour = (int) DateTimeImmutable::createFromInterface($now)
            ->setTimezone($this->timezone)
            ->format('G');

        return $hour >= $this->quietFromHour || $hour < $this->quietUntilHour;
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
