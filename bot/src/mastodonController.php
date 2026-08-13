<?php
// src/mastodonController.php

namespace PegelBot;

class mastodonController extends AboController
{
    private function SettingMapper(array $abo_details): array
    {
        return [
            'server'       => $abo_details['server'],
            'status_api'   => $abo_details['status_api'],
            'access_token' => $abo_details['access_token'],
            'account'      => $abo_details['beschreibung'],
        ];
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
                'Authorization: Bearer ' . $settings['access_token'],
            ];

            $media_ids = [];

            // Bild-Upload wenn notwendig
            if (!is_null($image)) {
                $this->_logger->debug("[Mastodon] Starte Media-Upload");

                $media_url = $settings['server'] . '/api/v2/media';

                $tmp_file = __DIR__ . "/../tmp/Ganglinie.png";
                if (file_put_contents($tmp_file, $image) === false) {
                    throw new \RuntimeException("Konnte temporäre Bilddatei nicht schreiben: {$tmp_file}");
                }

                $ch_media = curl_init();
                curl_setopt($ch_media, CURLOPT_URL, $media_url);
                curl_setopt($ch_media, CURLOPT_POST, 1);
                curl_setopt($ch_media, CURLOPT_POSTFIELDS, [
                    'file' => new \CURLFile($tmp_file, 'image/png', 'Ganglinie.png'),
                ]);
                curl_setopt($ch_media, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_media, CURLOPT_HTTPHEADER, $headers);

                $media_response = json_decode(curl_exec($ch_media));
                $media_http_code = curl_getinfo($ch_media, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch_media);
                curl_close($ch_media);

                if ($curl_error) {
                    throw new \RuntimeException("[Mastodon] cURL-Fehler beim Media-Upload: {$curl_error}");
                }

                if ($media_http_code !== 200 && $media_http_code !== 202) {
                    throw new \RuntimeException("[Mastodon] Media-Upload fehlgeschlagen (HTTP {$media_http_code}): " . json_encode($media_response));
                }

                if (empty($media_response->id)) {
                    throw new \RuntimeException("[Mastodon] Media-Upload fehlgeschlagen – keine media_id erhalten");
                }

                $this->_logger->info("[Mastodon] Media-Upload erfolgreich", ['media_id' => $media_response->id]);
                $media_ids[] = $media_response->id;
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

            $ch_status = curl_init();
            curl_setopt($ch_status, CURLOPT_URL, $settings['server'] . $settings['status_api']);
            curl_setopt($ch_status, CURLOPT_POST, 1);
            curl_setopt($ch_status, CURLOPT_POSTFIELDS, $status_data);
            curl_setopt($ch_status, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_status, CURLOPT_HTTPHEADER, $headers);

            $response = json_decode(curl_exec($ch_status));
            $http_code = curl_getinfo($ch_status, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch_status);
            curl_close($ch_status);

            if ($curl_error) {
                throw new \RuntimeException("[Mastodon] cURL-Fehler beim Posting: {$curl_error}");
            }

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