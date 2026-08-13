<?php

namespace WSA;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;

// Klasse um auf die WSA-REST-Services zuzugreifen
abstract class WSAServices {
    const API_URL = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/';

    // Funktion liefert die Messwerte einer Station zurück
    public static function getMeasurementsByStationUUID(
        \Monolog\Logger &$logger,
        string $station_uuid,
        \DateTime $startDate,
        ?\DateTime $endDate = null
    ): array {
        $return_array = array();
        $return_str = "";

        $client = new Client(['base_uri' => WSAServices::API_URL]);

        $request_str = "stations/{uuid}/W/measurements.json";
        $request_str = str_replace("{uuid}", $station_uuid, $request_str);
        $params = [
            'headers'   => ['Accept-Encoding' => 'gzip'],
            'query'     => 'start='.$startDate->format('c')
        ];
        if (!is_null($endDate)) {
            $params['query'] .= "&end=".$endDate->format('c');
        }

        $logger->debug("HTTP GET", [
            'uri' => WSAServices::API_URL.$request_str,
            'query' => $params['query']
        ]);

        try {
            $response = $client->get($request_str, $params);
            
            // Check if a header exists.
            if (!$response->hasHeader('content-type') || strpos($response->getHeader('content-type')[0], 'application/json') === false) {
                $logger->error("Kein JSON-Result", ['content-type' => $response->getHeader('content-type')[0], 'status' => $response->getStatusCode()]);
                return array();
            }

            $return_str = $response->getBody()->getContents();

        } catch (ClientException $e) {
            $logger->error("API-Client-Fehler", ['code' => $e->getResponse()->getStatusCode(), 'uri' => $e->getRequest()->getUri()->__toString()]);
            return array();
        } catch (ServerException $e) {
            $logger->error("API-Client-Fehler", ['code' => $e->getResponse()->getStatusCode(), 'uri' => $e->getRequest()->getUri()->__toString()]);
            return array();
        }

        $body_json = json_decode($return_str, true);
        
        //Daten verarbeiten
        foreach($body_json as $json_element) {
            $return_array[] = new Measurement($json_element['timestamp'], $json_element['value']);
        }

        return $return_array;
    }

    // Funktion liefert die Messwerte einer Station zurück
    public static function getMeasurementsTrendByStationUUID(
        string $station_uuid,
        ?int $days = 14,
        ?int $width = 600,
        ?int $height = 400
    ): string {
        $return = "";

        $client = new Client(['base_uri' => WSAServices::API_URL]);

        $request_str = "stations/{uuid}/W/measurements.png";
        $request_str = str_replace("{uuid}", $station_uuid, $request_str);
        $query_str = "start=P{days}D&width={width}&height={height}";
        $query_str = str_replace("{days}", $days, $query_str);
        $query_str = str_replace("{width}", $width, $query_str);
        $query_str = str_replace("{height}", $height, $query_str);

        $params = [
            'headers'   => ['Accept-Encoding' => 'gzip'],
            'query'     => $query_str
        ];

        try {
            $response = $client->get($request_str, $params);
            
            // Check if a header exists.
            if (!$response->hasHeader('content-type') || strpos($response->getHeader('content-type')[0], 'image/png') === false) {
                throw new \Exception("Kein PNG-Result; Code: ". $response->getStatusCode());
            }

            $return = $response->getBody()->getContents();

        } catch (ClientException $e) {
            echo "API-Client-Fehler; Code: ". $e->getResponse()->getStatusCode()."; URI: ".$e->getRequest()->getUri()->__toString();
        } catch (ServerException $e) {
            echo "API-Client-Fehler; Code: ". $e->getResponse()->getStatusCode()."; URI: ".$e->getRequest()->getUri()->__toString();
        }

        return $return;
    }
}