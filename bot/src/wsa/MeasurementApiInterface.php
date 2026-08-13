<?php

declare(strict_types=1);

namespace WSA;

use DateTimeInterface;

/**
 * Zugriff auf die Messwerte einer Pegel-Messstelle.
 *
 * Die Schnittstelle existiert, damit der Bot in Tests ohne Netzzugriff laeuft.
 * Die einzige produktive Umsetzung ist PegelOnlineApi.
 */
interface MeasurementApiInterface
{
    /**
     * Liefert die Messwerte einer Messstelle ab einem Zeitpunkt.
     *
     * @param string                 $stationUuid Kennung der Messstelle
     * @param DateTimeInterface      $start       Beginn des Zeitraums
     * @param DateTimeInterface|null $end         Ende des Zeitraums, offen wenn null
     *
     * @return list<Measurement> leeres Feld, wenn keine Werte zu ermitteln sind
     */
    public function fetchMeasurements(
        string $stationUuid,
        DateTimeInterface $start,
        ?DateTimeInterface $end = null,
    ): array;

    /**
     * Liefert die gerenderte Ganglinie einer Messstelle als PNG.
     *
     * @return string Binaerinhalt des Bildes; leer, wenn nichts zu holen war
     */
    public function fetchTrendImage(
        string $stationUuid,
        int $days = 14,
        int $width = 600,
        int $height = 400,
    ): string;
}
