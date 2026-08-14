<?php

namespace PegelBot;

use cjrasmussen\BlueskyApi\BlueskyApi;

class blueskyController extends AboController
{
    private function SettingMapper(array $abo_details): array
    {
        return [
            'handle'       => $abo_details['handle'],
            'app_password' => $abo_details['passwort'],
        ];
    }

    public function name(): string
    {
        return 'bluesky';
    }

    public function postNotify(array $abo_details, string $message_content): void
    {
        $this->_logger->debug("[Bluesky] postNotify()", ['handle' => $abo_details['handle']]);
        $this->post_intern($this->SettingMapper($abo_details), $message_content);
    }

    public function postTrend(array $abo_details, string $message_content, string $image): void
    {
        $this->_logger->debug("[Bluesky] postTrend()", ['handle' => $abo_details['handle']]);
        $this->post_intern($this->SettingMapper($abo_details), $message_content, $image);
    }

    private function post_intern(array $settings, string $status, ?string $image = null): void
    {
        $this->_logger->debug("[Bluesky] post_intern()", [
            'handle'      => $settings['handle'],
            'has_image'   => !is_null($image),
            'text_length' => strlen($status),
        ]);

        try {
            $bluesky = new BlueskyApi($settings['handle'], $settings['app_password']);

            $record = [
                'text'      => $status,
                'langs'     => ['de'],
                'createdAt' => date('c'),
                '$type'     => 'app.bsky.feed.post',
            ];

            // Bild-Upload wenn notwendig
            if (!is_null($image)) {
                $this->_logger->debug("[Bluesky] Starte Blob-Upload");

                $response = $bluesky->request('POST', 'com.atproto.repo.uploadBlob', [], $image, 'image/png');

                if (empty($response->blob)) {
                    throw new \RuntimeException("[Bluesky] Blob-Upload fehlgeschlagen – kein blob in der Antwort");
                }

                $this->_logger->info("[Bluesky] Blob-Upload erfolgreich", [
                    'mime_type' => $response->blob->mimeType ?? 'unbekannt',
                    'size'      => $response->blob->size ?? 'unbekannt',
                ]);

                $record['embed'] = [
                    '$type'  => 'app.bsky.embed.images',
                    'images' => [
                        [
                            'alt'   => 'Darstellung der Ganglinie',
                            'image' => $response->blob,
                        ],
                    ],
                ];
            }

            $args = [
                'collection' => 'app.bsky.feed.post',
                'repo'       => $bluesky->getAccountDid(),
                'record'     => $record,
            ];

            $this->_logger->debug("[Bluesky] Sende Post", ['repo' => $args['repo']]);
            $data = $bluesky->request('POST', 'com.atproto.repo.createRecord', $args);

            if (empty($data->uri)) {
                throw new \RuntimeException("[Bluesky] Post fehlgeschlagen – keine uri in der Antwort: " . json_encode($data));
            }

            $this->_logger->info("[Bluesky] Post erfolgreich", [
                'handle' => $settings['handle'],
                'uri'    => $data->uri,
                'cid'    => $data->cid ?? 'unbekannt',
            ]);
            echo "  [Bluesky] Post für {$settings['handle']} erstellt\n";

        } catch (\Throwable $e) {
            $this->_logger->error("[Bluesky] Fehler in post_intern()", [
                'handle'    => $settings['handle'],
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}