<?php
// src/twitterController.php

namespace PegelBot;

use Abraham\TwitterOAuth\TwitterOAuth;

class twitterController extends AboController
{
    private function SettingMapper(array $abo_details): array
    {
        return [
            'access_token'        => $abo_details['oauth_access_token'],
            'access_token_secret' => $abo_details['oauth_access_token_secret'],
            'consumer_key'        => $abo_details['consumer_key'],
            'consumer_secret'     => $abo_details['consumer_secret'],
            'account'             => $abo_details['beschreibung'],
        ];
    }

    public function postNotify(array $abo_details, string $message_content): void
    {
        $this->_logger->debug("[Twitter] postNotify()", ['account' => $abo_details['beschreibung']]);
        $this->post_intern($this->SettingMapper($abo_details), $message_content);
    }

    public function postTrend(array $abo_details, string $message_content, string $image): void
    {
        $this->_logger->debug("[Twitter] postTrend()", ['account' => $abo_details['beschreibung']]);
        $this->post_intern($this->SettingMapper($abo_details), $message_content, $image);
    }

    private function post_intern(array $settings, string $status, ?string $image = null): void
    {
        $this->_logger->debug("[Twitter] post_intern()", [
            'account'    => $settings['account'],
            'has_image'  => !is_null($image),
            'text_length' => strlen($status),
        ]);

        try {
            $connection = new TwitterOAuth(
                $settings['consumer_key'],
                $settings['consumer_secret'],
                $settings['access_token'],
                $settings['access_token_secret']
            );

            $data = ['text' => $status];

            // Bild-Upload wenn notwendig
            if (!is_null($image)) {
                $tmp_file = __DIR__ . "/../tmp/Ganglinie.png";
                $this->_logger->debug("[Twitter] Schreibe temporäre Bilddatei", ['path' => $tmp_file]);

                if (file_put_contents($tmp_file, $image) === false) {
                    throw new \RuntimeException("Konnte temporäre Bilddatei nicht schreiben: {$tmp_file}");
                }

                $this->_logger->debug("[Twitter] Starte Media-Upload (API v1.1)");
                $connection->setApiVersion('1.1');
                $media1 = $connection->upload('media/upload', ['media' => $tmp_file]);

                if (empty($media1->media_id_string)) {
                    throw new \RuntimeException("Media-Upload fehlgeschlagen – keine media_id erhalten");
                }

                $this->_logger->info("[Twitter] Media-Upload erfolgreich", ['media_id' => $media1->media_id_string]);
                $data['media'] = ['media_ids' => [$media1->media_id_string]];
            }

            $connection->setApiVersion('2');

            // Tweet absetzen
            $this->_logger->debug("[Twitter] Sende Tweet");
            $content = $connection->post("tweets", $data, true);
            $http_code = $connection->getLastHttpCode();

            if ($http_code !== 201) {
                $this->_logger->error("[Twitter] Tweet fehlgeschlagen", [
                    'account'   => $settings['account'],
                    'http_code' => $http_code,
                    'response'  => json_encode($content),
                ]);
                throw new \RuntimeException("[Twitter] Tweet fehlgeschlagen (HTTP {$http_code}): " . json_encode($content));
            }

            $this->_logger->info("[Twitter] Tweet erfolgreich gepostet", [
                'account'  => $settings['account'],
                'tweet_id' => $content->data->id ?? 'unbekannt',
            ]);
            echo "  [Twitter] Post für {$settings['account']} erstellt\n";

        } catch (\Throwable $e) {
            $this->_logger->error("[Twitter] Fehler in post_intern()", [
                'account'   => $settings['account'],
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            throw $e; // weiterwerfen, damit MessstellenController es ebenfalls loggt
        }
    }
}