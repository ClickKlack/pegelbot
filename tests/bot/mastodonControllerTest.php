<?php

declare(strict_types=1);

namespace Tests\bot;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PegelBot\mastodonController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Prüft den Mastodon-Kanal ohne Netzverbindung.
 *
 * Der eigentliche Anlass ist B15: Die media_ids gingen als Formularfeld
 * verloren, der Beitrag erschien ohne Bild. Die Tests halten fest, dass der
 * Status als JSON mit einer echten Liste übertragen wird.
 */
final class mastodonControllerTest extends TestCase
{
    private const SETTINGS = [
        'server'       => 'https://machteburch.social',
        'status_api'   => '/api/v1/statuses',
        'access_token' => 'geheim',
        'beschreibung' => '@elbpegel_md_sb@machteburch.social',
    ];

    /** @var list<array{request: Request}> Mitschnitt der abgesetzten Anfragen */
    private array $recordedRequests = [];

    /**
     * Baut einen Controller, der die übergebenen Antworten der Reihe nach liefert.
     *
     * @param list<Response|\Throwable> $responses
     */
    private function controllerWithResponses(array $responses): mastodonController
    {
        $this->recordedRequests = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->recordedRequests));

        return new mastodonController(
            new Logger('test', [new NullHandler()]),
            new Client(['handler' => $stack]),
        );
    }

    private function jsonResponse(string $body, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json'], $body);
    }

    private function mediaResponse(string $id = '117018742284225078', int $status = 200): Response
    {
        return $this->jsonResponse(json_encode(['id' => $id]), $status);
    }

    private function statusResponse(int $status = 200): Response
    {
        return $this->jsonResponse(json_encode([
            'id'  => '117018742295617031',
            'url' => 'https://machteburch.social/@elbpegel_md_sb/117018742295617031',
        ]), $status);
    }

    /**
     * Die Fachlogik schreibt noch mit echo auf die Standardausgabe. Bis das
     * herausgelöst ist, fängt der Test die Ausgabe selbst ab - sonst gilt er
     * PHPUnit als bedenklich.
     */
    private function callPostTrend(mastodonController $controller): string
    {
        ob_start();

        try {
            $controller->postTrend(self::SETTINGS, 'Pegelstand Strombrücke', 'PNG-Rohdaten');
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function decodedRequestBody(int $index): array
    {
        $body = (string) $this->recordedRequests[$index]['request']->getBody();

        return json_decode($body, true);
    }

    // ------------------------------------------------------------------
    //  B15 - die media_ids müssen als Liste ankommen
    // ------------------------------------------------------------------

    /**
     * Der Kern des Befundes: Als Formularfeld wurde aus der einelementigen
     * Liste eine bloße Zeichenkette, die Mastodon verwarf. Erwartet wird deshalb
     * ausdrücklich ein Array, nicht nur "die id steht irgendwo drin".
     */
    public function testTrendSendsMediaIdsAsArray(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse('117018742284225078'),
            $this->statusResponse(),
        ]);

        $this->callPostTrend($controller);

        $statusBody = $this->decodedRequestBody(1);

        self::assertIsArray($statusBody['media_ids']);
        self::assertSame(['117018742284225078'], $statusBody['media_ids']);
    }

    public function testTrendSendsStatusAsJson(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse(),
            $this->statusResponse(),
        ]);

        $this->callPostTrend($controller);

        $statusRequest = $this->recordedRequests[1]['request'];

        self::assertStringContainsString('application/json', $statusRequest->getHeaderLine('Content-Type'));
        self::assertSame(
            'https://machteburch.social/api/v1/statuses',
            (string) $statusRequest->getUri(),
        );
    }

    public function testTrendKeepsFixedLanguageAndVisibility(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse(),
            $this->statusResponse(),
        ]);

        $this->callPostTrend($controller);

        $statusBody = $this->decodedRequestBody(1);

        self::assertSame('Pegelstand Strombrücke', $statusBody['status']);
        self::assertSame('de', $statusBody['language']);
        self::assertSame('unlisted', $statusBody['visibility']);
    }

    // ------------------------------------------------------------------
    //  Media-Upload
    // ------------------------------------------------------------------

    public function testTrendUploadsImageBeforePostingStatus(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse(),
            $this->statusResponse(),
        ]);

        $this->callPostTrend($controller);

        self::assertCount(2, $this->recordedRequests);

        $mediaRequest = $this->recordedRequests[0]['request'];

        self::assertSame('https://machteburch.social/api/v2/media', (string) $mediaRequest->getUri());
        self::assertStringContainsString('multipart/form-data', $mediaRequest->getHeaderLine('Content-Type'));
        self::assertStringContainsString('PNG-Rohdaten', (string) $mediaRequest->getBody());
        self::assertStringContainsString('filename="Ganglinie.png"', (string) $mediaRequest->getBody());
    }

    public function testBothRequestsCarryTheAccessToken(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse(),
            $this->statusResponse(),
        ]);

        $this->callPostTrend($controller);

        foreach ($this->recordedRequests as $recorded) {
            self::assertSame('Bearer geheim', $recorded['request']->getHeaderLine('Authorization'));
        }
    }

    /**
     * Randfall: Bei größeren Bildern antwortet Mastodon mit 202, weil die
     * Verarbeitung noch läuft. Das ist kein Fehler.
     */
    public function testAcceptedMediaUploadIsNotAnError(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse('117018742284225078', 202),
            $this->statusResponse(),
        ]);

        $this->callPostTrend($controller);

        self::assertSame(['117018742284225078'], $this->decodedRequestBody(1)['media_ids']);
    }

    public function testFailedMediaUploadThrowsAndSkipsTheStatus(): void
    {
        $controller = $this->controllerWithResponses([
            $this->jsonResponse('{"error":"Datei zu groß"}', 422),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Media-Upload fehlgeschlagen (HTTP 422)');

        $this->callPostTrend($controller);
    }

    /**
     * Randfall: HTTP 200, aber keine id im Rumpf. Ohne die Prüfung ginge ein
     * Beitrag mit leerer Medienliste hinaus.
     */
    public function testMediaUploadWithoutIdThrows(): void
    {
        $controller = $this->controllerWithResponses([
            $this->jsonResponse('{}'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keine media_id erhalten');

        $this->callPostTrend($controller);
    }

    // ------------------------------------------------------------------
    //  Benachrichtigung ohne Bild
    // ------------------------------------------------------------------

    public function testNotifySendsNoMediaIdsAndNoUpload(): void
    {
        $controller = $this->controllerWithResponses([
            $this->statusResponse(),
        ]);

        ob_start();

        try {
            $controller->postNotify(self::SETTINGS, 'Pegelstand Strombrücke');
        } finally {
            ob_end_clean();
        }

        self::assertCount(1, $this->recordedRequests);

        $statusBody = $this->decodedRequestBody(0);

        self::assertArrayNotHasKey('media_ids', $statusBody);
        self::assertSame('Pegelstand Strombrücke', $statusBody['status']);
    }

    // ------------------------------------------------------------------
    //  Fehlgeschlagener Status
    // ------------------------------------------------------------------

    public function testFailedStatusThrows(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse(),
            $this->jsonResponse('{"error":"Token abgelaufen"}', 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post fehlgeschlagen (HTTP 401)');

        $this->callPostTrend($controller);
    }

    /**
     * Randfall: Einer Umleitung wird bewusst nicht gefolgt, sonst würde aus dem
     * POST ein GET und der Beitrag ginge verloren. Sie gilt als Fehlschlag,
     * genau wie zuvor mit cURL.
     */
    public function testRedirectIsNotFollowedAndCountsAsFailure(): void
    {
        $controller = $this->controllerWithResponses([
            $this->mediaResponse(),
            new Response(302, ['Location' => 'https://andere.instanz/api/v1/statuses']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post fehlgeschlagen (HTTP 302)');

        $this->callPostTrend($controller);
    }
}
