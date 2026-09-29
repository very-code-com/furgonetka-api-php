<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Tests\Support\LocalServer;
use VeryCodeCom\FurgonetkaApi\Transport\CurlTransport;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Runs the real cURL transport against a server on the loopback interface (see {@see LocalServer}).
 * What is checked here is what only cURL can show: which bytes go out for each verb, and what
 * comes back for each kind of answer Furgonetka gives.
 */
final class CurlTransportTest extends TestCase
{
    private static LocalServer $server;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$server = LocalServer::start();
        } catch (\RuntimeException $e) {
            self::markTestSkipped('No loopback server available: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$server)) {
            self::$server->stop();
        }
    }

    private function send(TransportRequest $request): TransportResponse
    {
        return (new CurlTransport())->send($request);
    }

    /**
     * What the server saw, decoded from its /echo answer.
     *
     * @return array{method: string, uri: string, headers: array<string, string>, body: string}
     */
    private function echoed(TransportResponse $response): array
    {
        /** @var array{method: string, uri: string, headers: array<string, string>, body_base64: string} $data */
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return [
            'method' => $data['method'],
            'uri' => $data['uri'],
            'headers' => $data['headers'],
            'body' => (string) base64_decode($data['body_base64'], true),
        ];
    }

    // -- Verbs and bodies -----------------------------------------------------

    public function testGetSendsHeadersAndQueryAndNoBody(): void
    {
        $seen = $this->echoed($this->send(new TransportRequest(
            self::$server->url('/echo?limit=10&filters[state]=waiting'),
            'GET',
            headers: ['Accept' => 'application/vnd.furgonetka.v2+json', 'X-Language' => 'en_GB'],
        )));

        self::assertSame('GET', $seen['method']);
        self::assertSame('/echo?limit=10&filters[state]=waiting', $seen['uri']);
        self::assertSame('application/vnd.furgonetka.v2+json', $seen['headers']['Accept']);
        self::assertSame('en_GB', $seen['headers']['X-Language']);
        self::assertSame('', $seen['body']);
    }

    public function testPostSendsTheBodyAndTheContentType(): void
    {
        $seen = $this->echoed($this->send(new TransportRequest(
            self::$server->url('/echo'),
            'POST',
            '{"service_id":"1"}',
            ['Content-Type' => 'application/vnd.furgonetka.v2+json'],
        )));

        self::assertSame('POST', $seen['method']);
        self::assertSame('application/vnd.furgonetka.v2+json', $seen['headers']['Content-Type']);
        self::assertSame('{"service_id":"1"}', $seen['body']);
    }

    public function testPutSendsTheBody(): void
    {
        // The asynchronous commands (order, pickup, cancel, documents) are all PUT with a JSON body.
        $seen = $this->echoed($this->send(new TransportRequest(
            self::$server->url('/echo'),
            'PUT',
            '{"package_ids":["1","2"]}',
            ['Content-Type' => 'application/vnd.furgonetka.v1+json'],
        )));

        self::assertSame('PUT', $seen['method']);
        self::assertSame('{"package_ids":["1","2"]}', $seen['body']);
    }

    public function testPutWithoutABodyStillSendsAnEmptyOne(): void
    {
        $seen = $this->echoed($this->send(new TransportRequest(self::$server->url('/echo'), 'PUT')));

        self::assertSame('PUT', $seen['method']);
        self::assertSame('0', $seen['headers']['Content-Length']);
    }

    public function testPostWithoutABodyStillSendsAnEmptyOne(): void
    {
        $seen = $this->echoed($this->send(new TransportRequest(self::$server->url('/echo'), 'POST')));

        self::assertSame('POST', $seen['method']);
        self::assertSame('0', $seen['headers']['Content-Length']);
    }

    public function testDeleteCanCarryABody(): void
    {
        // Bulk deletion and cancellation send the list of shipments as the body of a DELETE.
        $seen = $this->echoed($this->send(new TransportRequest(
            self::$server->url('/echo'),
            'DELETE',
            '{"packages":["10","11"]}',
            ['Content-Type' => 'application/vnd.furgonetka.v1+json'],
        )));

        self::assertSame('DELETE', $seen['method']);
        self::assertSame('{"packages":["10","11"]}', $seen['body']);
    }

    public function testDeleteWithoutABodyHasNoBody(): void
    {
        $seen = $this->echoed($this->send(new TransportRequest(self::$server->url('/echo'), 'DELETE')));

        self::assertSame('DELETE', $seen['method']);
        self::assertSame('', $seen['body']);
        self::assertArrayNotHasKey('Content-Length', $seen['headers']);
    }

    public function testFormRequestReachesTheServerFormEncoded(): void
    {
        // The shape of a refresh: Basic auth for the application, form body for the grant.
        $seen = $this->echoed($this->send(TransportRequest::form(
            self::$server->url('/echo'),
            ['grant_type' => 'refresh_token', 'refresh_token' => 'a b&c=d'],
            ['Authorization' => 'Basic Zm9vOmJhcg=='],
        )));

        self::assertSame('POST', $seen['method']);
        self::assertSame('application/x-www-form-urlencoded', $seen['headers']['Content-Type']);
        self::assertSame('Basic Zm9vOmJhcg==', $seen['headers']['Authorization']);

        parse_str($seen['body'], $fields);
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'a b&c=d'], $fields);
    }

    public function testALargeBinaryBodyArrivesByteForByte(): void
    {
        // Above cURL's threshold for "Expect: 100-continue", which the transport switches off.
        $payload = random_bytes(1_500_000);

        $seen = $this->echoed($this->send(new TransportRequest(
            self::$server->url('/echo'),
            'POST',
            $payload,
            ['Content-Type' => 'application/octet-stream'],
        )));

        self::assertSame($payload, $seen['body']);
        self::assertArrayNotHasKey('Expect', $seen['headers']);
    }

    public function testMultibyteTextIsSentUntouched(): void
    {
        $seen = $this->echoed($this->send(new TransportRequest(
            self::$server->url('/echo'),
            'POST',
            '{"street":"Marsza' . "\u{142}" . 'kowska 100"}',
            ['Content-Type' => 'application/json'],
        )));

        self::assertSame("{\"street\":\"Marsza\u{142}kowska 100\"}", $seen['body']);
    }

    // -- Responses ------------------------------------------------------------

    public function testABinaryLabelIsReturnedByteExact(): void
    {
        $response = $this->send(new TransportRequest(self::$server->url('/pdf')));

        self::assertSame(200, $response->statusCode);
        self::assertTrue($response->isPdf());
        self::assertSame("%PDF-1.4\n" . implode('', array_map('chr', range(0, 255))), $response->body);
    }

    public function testATextLabelKeepsItsContentType(): void
    {
        $response = $this->send(new TransportRequest(self::$server->url('/zpl')));

        self::assertSame('text/plain', $response->contentType());
        self::assertSame("^XA^FO50,50^FDMarsza\u{142}kowska^FS^XZ", $response->body);
        self::assertFalse($response->isPdf());
    }

    public function testAnEmptyNoContentResponseIsNotAnError(): void
    {
        // 204 is a normal answer: a valid shipment on /packages/validate, a label not ready yet.
        $response = $this->send(new TransportRequest(self::$server->url('/no-content')));

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertTrue($response->isSuccess());
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        $response = $this->send(new TransportRequest(self::$server->url('/status/400')));

        self::assertSame(400, $response->statusCode);
        self::assertTrue($response->isJson());
        self::assertStringContainsString('/receiver/postcode', $response->body);
    }

    public function testTheRateLimitHeaderIsReadOnAThrottledResponse(): void
    {
        $response = $this->send(new TransportRequest(self::$server->url('/status/429')));

        self::assertSame(429, $response->statusCode);
        self::assertSame(30, $response->retryAfterSeconds());
    }

    public function testResponseHeadersAreLowerCasedAndRepeatedOnesJoined(): void
    {
        $response = $this->send(new TransportRequest(self::$server->url('/multi')));

        self::assertSame('first, second', $response->header('X-Multi'));
        self::assertSame('one', $response->header('x-single'));
    }

    public function testACompressedResponseIsDecodedTransparently(): void
    {
        $response = $this->send(new TransportRequest(self::$server->url('/gzip')));

        self::assertSame('{"compressed":true}', $response->body);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        // The request carries a bearer token; replaying it wherever a redirect points would leak it.
        $response = $this->send(new TransportRequest(self::$server->url('/redirect'), headers: ['Authorization' => 'Bearer x']));

        self::assertSame(302, $response->statusCode);
        self::assertSame('/echo', $response->header('location'));
        self::assertSame('', $response->body);
    }

    // -- Failures -------------------------------------------------------------

    public function testAConnectionFailureBecomesATransportException(): void
    {
        $request = new TransportRequest(LocalServer::closedUrl() . 'packages?token=abc', connectTimeout: 2);

        try {
            $this->send($request);
            self::fail('Expected a transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertStringContainsString('cURL error #7', $e->getMessage());
            self::assertStringContainsString('GET http://127.0.0.1:', $e->getMessage());
            self::assertStringNotContainsString('token=abc', $e->getMessage(), 'The query string stays out of messages.');
        }
    }

    public function testATimeoutBecomesATransportException(): void
    {
        $request = new TransportRequest(self::$server->url('/slow'), timeout: 1);

        $this->expectException(FurgonetkaApiTransportException::class);
        $this->expectExceptionMessage('cURL error #28');

        $this->send($request);
    }
}
