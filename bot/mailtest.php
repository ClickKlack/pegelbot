<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\TransferStats;

require_once("bootstrap.php");

$client = new Client(['base_uri' => "https://www.google.de/"]);

$request_str = "";
$params = [
    'headers'   => ['Accept-Encoding' => 'gzip'],
    'on_stats' => function (TransferStats $stats) use (&$url) {
        $url = $stats->getEffectiveUri();
    }
];

try {
    $response = $client->get($request_str, $params);
    
    echo $url;

    $return_str = $response->getBody()->getContents();

} catch (ClientException $e) {
    echo "API-Client-Fehler; Code: ". $e->getResponse()->getStatusCode()."; URI: ".$e->getRequest()->getUri()->__toString();
} catch (ServerException $e) {
    echo "API-Client-Fehler; Code: ". $e->getResponse()->getStatusCode()."; URI: ".$e->getRequest()->getUri()->__toString();
}