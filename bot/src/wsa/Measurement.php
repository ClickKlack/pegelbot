<?php

declare(strict_types=1);

namespace WSA;

use DateTime;
use DateTimeZone;

/**
 * Ein einzelner Messwert einer Pegel-Messstelle.
 */
class Measurement {
    private DateTime $timestamp;
    private int $value;

    /**
     * @param string    $timestamp Zeitstempel in beliebiger Zeitzone
     * @param int|float $value     Wasserstand in Zentimetern
     */
    public function __construct(string $timestamp, int|float $value) {
        // in DateTime umwandeln
        $this->timestamp = new DateTime($timestamp);
        // den Timestamp auf jeden Fall in UTC halten
        $this->timestamp->setTimezone(new DateTimeZone('UTC'));

        // PEGELONLINE liefert die Werte als Gleitkommazahl, praktisch immer ohne
        // Nachkommaanteil (etwa 38.0). Datenmodell und Zielspalte messwerte.messwert
        // sind ganzzahlig, deshalb wird hier ausdruecklich gerundet. Die frueheren
        // Fassungen schnitten den Nachkommaanteil still ab, weil sie ohne strikte
        // Typen liefen - siehe Befund B9.
        $this->value = (int) round($value);
    }

    public function getTimestamp(): DateTime {
        return $this->timestamp;
    }

    public function getValue(): int {
        return $this->value;
    }
}
