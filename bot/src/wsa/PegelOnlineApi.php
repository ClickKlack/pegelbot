<?php

declare(strict_types=1);

namespace WSA;

use DateTimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

/**
 * Zugriff auf die REST-Schnittstelle von PEGELONLINE der Wasserstrassen- und
 * Schifffahrtsverwaltung des Bundes.
 *
 * HTTP-Client und Protokoll werden hereingereicht, damit die Klasse in Tests
 * ohne Netzzugriff arbeitet. Die vorherige Fassung erzeugte den Client selbst
 * und bestand aus statischen Methoden - beides war nicht ersetzbar.
 */
final class PegelOnlineApi implements MeasurementApiInterface
{
    public const API_URL = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetchMeasurements(
        string $stationUuid,
        DateTimeInterface $start,
        ?DateTimeInterface $end = null,
    ): array {
        $path  = 'stations/' . $stationUuid . '/W/measurements.json';
        $query = 'start=' . $start->format('c');

        if ($end !== null) {
            $query .= '&end=' . $end->format('c');
        }

        $this->logger->debug('HTTP GET', [
            'uri'   => self::API_URL . $path,
            'query' => $query,
        ]);

        // Hinweis: Serverfehler (5xx) werden hier bewusst noch nicht gefangen -
        // das Verhalten entspricht dem Stand vor der Verlagerung und wird als
        // Befund B1 gesondert behoben.
        try {
            $response = $this->client->request('GET', $path, [
                'headers' => ['Accept-Encoding' => 'gzip'],
                'query'   => $query,
            ]);

            $contentType = $response->getHeaderLine('content-type');

            if (!str_contains($contentType, 'application/json')) {
                $this->logger->error('Kein JSON-Result', [
                    'content-type' => $contentType,
                    'status'       => $response->getStatusCode(),
                ]);

                return [];
            }

            $body = $response->getBody()->getContents();
        } catch (ClientException $e) {
            $this->logger->error('API-Client-Fehler', [
                'code' => $e->getResponse()->getStatusCode(),
                'uri'  => (string) $e->getRequest()->getUri(),
            ]);

            return [];
        }

        $measurements = [];

        foreach (json_decode($body, true) as $element) {
            $measurements[] = new Measurement($element['timestamp'], $element['value']);
        }

        return $measurements;
    }

    public function fetchTrendImage(
        string $stationUuid,
        int $days = 14,
        int $width = 600,
        int $height = 400,
    ): string {
        $path  = 'stations/' . $stationUuid . '/W/measurements.png';
        $query = 'start=P' . $days . 'D&width=' . $width . '&height=' . $height;

        $this->logger->debug('HTTP GET', [
            'uri'   => self::API_URL . $path,
            'query' => $query,
        ]);

        try {
            $response = $this->client->request('GET', $path, [
                'headers' => ['Accept-Encoding' => 'gzip'],
                'query'   => $query,
            ]);

            $contentType = $response->getHeaderLine('content-type');

            if (!str_contains($contentType, 'image/png')) {
                throw new \RuntimeException(
                    'Kein PNG-Result; Code: ' . $response->getStatusCode()
                );
            }

            return $response->getBody()->getContents();
        } catch (ClientException $e) {
            $this->logger->error('API-Client-Fehler', [
                'code' => $e->getResponse()->getStatusCode(),
                'uri'  => (string) $e->getRequest()->getUri(),
            ]);

            return '';
        }
    }
}
