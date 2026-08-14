<?php

declare(strict_types=1);

namespace PegelBot;

use GuzzleHttp\ClientInterface;

class mastodonController extends AboController
{
    private ClientInterface $client;

    /**
     * Der HTTP-Client wird hereingereicht, damit die Klasse in Tests ohne Netz
     * arbeitet.
     *
     * Er war vorerst wahlfrei, weil die Kanalcontroller ueber einen dynamisch
     * gebauten Klassennamen mit genau einem Argument erzeugt wurden. Seit die
     * Kanaele in bootstrap.php aufgebaut und in der ChannelRegistry abgelegt
     * werden, ist er Pflicht.
     */
    public function __construct(\Monolog\Logger $logger, ClientInterface $client)
    {
        parent::__construct($logger);

        $this->client = $client;
    }

    private function SettingMapper(array $abo_details): array
    {
        return [
            'server'       => $abo_details['server'],
            'status_api'   => $abo_details['status_api'],
            'access_token' => $abo_details['access_token'],
            'account'      => $abo_details['beschreibung'],
        ];
    }

    public function name(): string
    {
        return 'mastodon';
    }

    public function postNotify(array $abo_details, string $message_content): void
    {
        $this->_logger->debug("[Mastodon] postNotify()", ['account' => $abo_details['beschreibung']]);
        $this->post_intern($this->SettingMapper($abo_details), $message_content);
    }

    public function postTrend(array $abo_details, string $message_content, string $image): void
    {
        $this->_logger->debug("[Mastodon] postTrend()", ['account' => $abo_details['beschreibung']]);
        $this->post_intern($this->SettingMapper($abo_details), $message_content, $image);
    }

    private function post_intern(array $settings, string $status, ?string $image = null): void
    {
        $this->_logger->debug("[Mastodon] post_intern()", [
            'account'     => $settings['account'],
            'has_image'   => !is_null($image),
            'text_length' => strlen($status),
        ]);

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $settings['access_token'],
            ];

            $media_ids = [];

            // Bild-Upload wenn notwendig
            if (!is_null($image)) {
                $this->_logger->debug("[Mastodon] Starte Media-Upload");

                $media_response = $this->client->request('POST', $settings['server'] . '/api/v2/media', [
                    'headers'   => $headers,
                    'multipart' => [
                        [
                            'name'     => 'file',
                            'contents' => $image,
                            'filename' => 'Ganglinie.png',
                            'headers'  => ['Content-Type' => 'image/png'],
                        ],
                    ],
                    // Wie zuvor mit cURL wird Umleitungen nicht gefolgt: Eine
                    // umgeleitete Anfrage verlöre sonst die POST-Methode.
                    'http_errors'     => false,
                    'allow_redirects' => false,
                ]);

                $media_http_code = $media_response->getStatusCode();
                $media_payload   = json_decode((string) $media_response->getBody());

                if ($media_http_code !== 200 && $media_http_code !== 202) {
                    throw new \RuntimeException("[Mastodon] Media-Upload fehlgeschlagen (HTTP {$media_http_code}): " . json_encode($media_payload));
                }

                if (empty($media_payload->id)) {
                    throw new \RuntimeException("[Mastodon] Media-Upload fehlgeschlagen – keine media_id erhalten");
                }

                $this->_logger->info("[Mastodon] Media-Upload erfolgreich", ['media_id' => $media_payload->id]);
                $media_ids[] = $media_payload->id;
            }

            // Status posten
            $status_data = [
                'status'     => $status,
                'language'   => 'de',
                'visibility' => 'unlisted',
            ];

            if (!empty($media_ids)) {
                $status_data['media_ids'] = $media_ids;
            }

            // Bewusst als JSON: In einem Formular-Rumpf fällt die Liste der
            // media_ids in sich zusammen und kommt als bloße Zeichenkette an.
            // Mastodon verwirft sie dann stillschweigend — der Beitrag erscheint
            // ohne Bild, obwohl der Upload gelungen ist.
            $status_response = $this->client->request('POST', $settings['server'] . $settings['status_api'], [
                'headers'     => $headers,
                'json'        => $status_data,
                'http_errors'     => false,
                'allow_redirects' => false,
            ]);

            $http_code = $status_response->getStatusCode();
            $response  = json_decode((string) $status_response->getBody());

            if ($http_code !== 200) {
                $this->_logger->error("[Mastodon] Post fehlgeschlagen", [
                    'account'   => $settings['account'],
                    'http_code' => $http_code,
                    'response'  => json_encode($response),
                ]);
                throw new \RuntimeException("[Mastodon] Post fehlgeschlagen (HTTP {$http_code}): " . json_encode($response));
            }

            $this->_logger->info("[Mastodon] Post erfolgreich", [
                'account'  => $settings['account'],
                'post_id'  => $response->id ?? 'unbekannt',
                'post_url' => $response->url ?? 'unbekannt',
            ]);

            echo "  [Mastodon] Post für {$settings['account']} erstellt\n";

        } catch (\Throwable $e) {
            $this->_logger->error("[Mastodon] Fehler in post_intern()", [
                'account'   => $settings['account'],
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
