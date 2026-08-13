<?php

declare(strict_types=1);

namespace Tests\bot\wsa;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use WSA\Measurement;
use WSA\PegelOnlineApi;

/**
 * Prueft den Zugriff auf PEGELONLINE ohne Netzverbindung.
 *
 * Erst durch das Hereinreichen des HTTP-Clients sind diese Tests moeglich -
 * die vorherige Fassung erzeugte ihren Client selbst.
 */
final class PegelOnlineApiTest extends TestCase
{
    private const UUID = 'ccccb57f-a2f9-4183-ae88-5710d3afaefd';

    /** @var list<Request> Mitschnitt der abgesetzten Anfragen */
    private array $recordedRequests = [];

    /**
     * Baut eine API-Instanz, die die uebergebenen Antworten der Reihe nach liefert.
     *
     * @param list<Response|\Throwable> $responses
     */
    private function apiWithResponses(array $responses): PegelOnlineApi
    {
        $this->recordedRequests = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->recordedRequests));

        return new PegelOnlineApi(
            new Client(['handler' => $stack, 'base_uri' => PegelOnlineApi::API_URL]),
            new NullLogger(),
        );
    }

    private function jsonResponse(string $body, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json;charset=UTF-8'], $body);
    }

    private function start(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-13 06:00:00', new DateTimeZone('UTC'));
    }

    // ------------------------------------------------------------------
    //  fetchMeasurements() - Normalfall
    // ------------------------------------------------------------------

    public function testFetchMeasurementsMapsResponseToMeasurements(): void
    {
        // Die Vorlage bildet die echte Antwort nach: PEGELONLINE liefert die Werte
        // als Gleitkommazahl, auch ohne Nachkommaanteil.
        $api = $this->apiWithResponses([
            $this->jsonResponse('[
                {"timestamp":"2026-08-13T06:00:00+02:00","value":214.0},
                {"timestamp":"2026-08-13T06:15:00+02:00","value":216.0}
            ]'),
        ]);

        $measurements = $api->fetchMeasurements(self::UUID, $this->start());

        self::assertCount(2, $measurements);
        self::assertContainsOnlyInstancesOf(Measurement::class, $measurements);
        self::assertSame(214, $measurements[0]->getValue());
        self::assertSame(216, $measurements[1]->getValue());
    }

    public function testFetchMeasurementsNormalisesTimestampsToUtc(): void
    {
        $api = $this->apiWithResponses([
            $this->jsonResponse('[{"timestamp":"2026-08-13T06:00:00+02:00","value":214.0}]'),
        ]);

        $measurements = $api->fetchMeasurements(self::UUID, $this->start());

        self::assertSame('UTC', $measurements[0]->getTimestamp()->getTimezone()->getName());
        self::assertSame('2026-08-13 04:00:00', $measurements[0]->getTimestamp()->format('Y-m-d H:i:s'));
    }

    public function testFetchMeasurementsReturnsEmptyArrayForEmptyResponse(): void
    {
        $api = $this->apiWithResponses([$this->jsonResponse('[]')]);

        self::assertSame([], $api->fetchMeasurements(self::UUID, $this->start()));
    }

    public function testFetchMeasurementsRequestsExpectedPathAndQuery(): void
    {
        $api = $this->apiWithResponses([$this->jsonResponse('[]')]);

        $api->fetchMeasurements(self::UUID, $this->start());

        $uri = $this->recordedRequests[0]['request']->getUri();

        self::assertStringEndsWith('stations/' . self::UUID . '/W/measurements.json', $uri->getPath());

        // Der Zeitzonenversatz wird unkodiert uebertragen. Das ist der Stand seit
        // jeher und wird von PEGELONLINE akzeptiert; festgehalten als Befund B13.
        self::assertSame('start=2026-08-13T06:00:00+00:00', $uri->getQuery());
    }

    public function testFetchMeasurementsAppendsEndDateWhenGiven(): void
    {
        $api = $this->apiWithResponses([$this->jsonResponse('[]')]);

        $api->fetchMeasurements(
            self::UUID,
            $this->start(),
            new DateTimeImmutable('2026-08-13 12:00:00', new DateTimeZone('UTC')),
        );

        $query = $this->recordedRequests[0]['request']->getUri()->getQuery();

        self::assertStringContainsString('start=2026-08-13T06:00:00+00:00', $query);
        self::assertStringContainsString('end=2026-08-13T12:00:00+00:00', $query);
    }

    // ------------------------------------------------------------------
    //  fetchMeasurements() - Fehlerfaelle
    // ------------------------------------------------------------------

    public function testFetchMeasurementsReturnsEmptyArrayForNonJsonContentType(): void
    {
        $api = $this->apiWithResponses([
            new Response(200, ['content-type' => 'text/html'], '<html>Wartungsarbeiten</html>'),
        ]);

        self::assertSame([], $api->fetchMeasurements(self::UUID, $this->start()));
    }

    public function testFetchMeasurementsReturnsEmptyArrayOnClientError(): void
    {
        $api = $this->apiWithResponses([new Response(404, [], 'nicht gefunden')]);

        self::assertSame([], $api->fetchMeasurements(self::UUID, $this->start()));
    }

    /**
     * Befund B1: Eine Stoerung bei PEGELONLINE hat frueher den kompletten Botlauf
     * beendet, weil der zugehoerige use-Import fehlte und die Ausnahme deshalb
     * nie gefangen wurde.
     *
     * @param int $status ein Serverfehler-Code
     */
    #[DataProvider('serverErrorCodes')]
    public function testFetchMeasurementsReturnsEmptyArrayOnServerError(int $status): void
    {
        $api = $this->apiWithResponses([new Response($status, [], 'Stoerung')]);

        self::assertSame([], $api->fetchMeasurements(self::UUID, $this->start()));
    }

    /** @return iterable<string, array{int}> */
    public static function serverErrorCodes(): iterable
    {
        yield 'interner Fehler' => [500];
        yield 'Wartung'         => [503];
        yield 'Zeitueberschreitung am Gateway' => [504];
    }

    /**
     * Der Lauf muss nach einer Stoerung weitergehen: Faellt eine Messstelle aus,
     * duerfen die folgenden trotzdem abgerufen werden.
     */
    public function testServerErrorDoesNotAffectSubsequentCalls(): void
    {
        $api = $this->apiWithResponses([
            new Response(503, [], 'Stoerung'),
            $this->jsonResponse('[{"timestamp":"2026-08-13T06:00:00+02:00","value":214.0}]'),
        ]);

        self::assertSame([], $api->fetchMeasurements(self::UUID, $this->start()));
        self::assertCount(1, $api->fetchMeasurements(self::UUID, $this->start()));
    }

    public function testFetchTrendImageReturnsEmptyStringOnServerError(): void
    {
        $api = $this->apiWithResponses([new Response(503, [], 'Stoerung')]);

        self::assertSame('', $api->fetchTrendImage(self::UUID));
    }

    // ------------------------------------------------------------------
    //  fetchTrendImage()
    // ------------------------------------------------------------------

    public function testFetchTrendImageReturnsBinaryContent(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . 'Bilddaten';
        $api = $this->apiWithResponses([new Response(200, ['content-type' => 'image/png'], $png)]);

        self::assertSame($png, $api->fetchTrendImage(self::UUID));
    }

    public function testFetchTrendImageUsesDefaultParameters(): void
    {
        $api = $this->apiWithResponses([new Response(200, ['content-type' => 'image/png'], 'x')]);

        $api->fetchTrendImage(self::UUID);

        $uri = $this->recordedRequests[0]['request']->getUri();

        self::assertStringEndsWith('stations/' . self::UUID . '/W/measurements.png', $uri->getPath());
        self::assertSame('start=P14D&width=600&height=400', urldecode($uri->getQuery()));
    }

    public function testFetchTrendImageHonoursGivenParameters(): void
    {
        $api = $this->apiWithResponses([new Response(200, ['content-type' => 'image/png'], 'x')]);

        $api->fetchTrendImage(self::UUID, 7, 800, 300);

        self::assertSame(
            'start=P7D&width=800&height=300',
            urldecode($this->recordedRequests[0]['request']->getUri()->getQuery()),
        );
    }

    public function testFetchTrendImageThrowsForNonImageContentType(): void
    {
        $api = $this->apiWithResponses([
            new Response(200, ['content-type' => 'text/html'], '<html></html>'),
        ]);

        $this->expectException(RuntimeException::class);

        $api->fetchTrendImage(self::UUID);
    }

    public function testFetchTrendImageReturnsEmptyStringOnClientError(): void
    {
        $api = $this->apiWithResponses([new Response(404, [], 'nicht gefunden')]);

        self::assertSame('', $api->fetchTrendImage(self::UUID));
    }
}
