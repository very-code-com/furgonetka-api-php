<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

final class TransportResponseTest extends TestCase
{
    private const ACCESS = 'eyJ.access-token-value.sig';
    private const REFRESH = 'def50200refresh-token-value';

    /** The body shape of a successful call to the token endpoint. */
    private static function tokenBody(): string
    {
        return sprintf(
            '{"token_type":"Bearer","expires_in":2595600,"access_token":"%s","refresh_token":"%s"}',
            self::ACCESS,
            self::REFRESH,
        );
    }

    public function testHeaderNamesAreLowercasedAndLookedUpCaseInsensitively(): void
    {
        $response = new TransportResponse(200, 'body', ['Content-Type' => 'application/json; charset=utf-8', 'X-RateLimit-Limit' => '500']);

        self::assertSame('application/json; charset=utf-8', $response->header('CONTENT-TYPE'));
        self::assertSame('500', $response->header('x-ratelimit-limit'));
        self::assertNull($response->header('x-missing'));
        self::assertSame('application/json', $response->contentType());
    }

    public function testContentTypeIsEmptyWhenTheHeaderIsAbsent(): void
    {
        self::assertSame('', (new TransportResponse(204, ''))->contentType());
    }

    public function testSuccessRange(): void
    {
        self::assertTrue((new TransportResponse(200, ''))->isSuccess());
        self::assertTrue((new TransportResponse(204, ''))->isSuccess());
        self::assertTrue((new TransportResponse(299, ''))->isSuccess());
        self::assertFalse((new TransportResponse(199, ''))->isSuccess());
        self::assertFalse((new TransportResponse(302, ''))->isSuccess());
        self::assertFalse((new TransportResponse(401, ''))->isSuccess());
    }

    public function testJsonIsDetectedByContentType(): void
    {
        self::assertTrue((new TransportResponse(200, '{}', ['content-type' => 'application/json']))->isJson());
        self::assertTrue((new TransportResponse(200, '{}', ['content-type' => 'application/json; charset=utf-8']))->isJson());
        self::assertTrue((new TransportResponse(200, '{}', ['content-type' => 'application/vnd.furgonetka.v2+json']))->isJson());
        self::assertFalse((new TransportResponse(502, '<html lang="en">', ['content-type' => 'text/html']))->isJson());
        self::assertFalse((new TransportResponse(200, '{}'))->isJson());
    }

    public function testPdfIsDetectedByContentTypeOrByMagicBytes(): void
    {
        self::assertTrue((new TransportResponse(200, 'x', ['content-type' => 'application/pdf']))->isPdf());
        self::assertTrue((new TransportResponse(200, "%PDF-1.4\n..."))->isPdf());
        self::assertFalse((new TransportResponse(200, '^XA^FDlabel^FS^XZ', ['content-type' => 'text/plain']))->isPdf());
    }

    // -- Retry-After -----------------------------------------------------------------

    public function testRetryAfterReadsTheFurgonetkaHeader(): void
    {
        self::assertSame(1800, (new TransportResponse(429, '', ['x-ratelimit-retry-after' => '1800']))->retryAfterSeconds());
    }

    public function testRetryAfterZeroMeansNothingToWaitFor(): void
    {
        // Furgonetka sends "X-RateLimit-Retry-After: 0" on every response while under the limit.
        self::assertNull((new TransportResponse(200, '', ['x-ratelimit-retry-after' => '0']))->retryAfterSeconds());
    }

    public function testTheFurgonetkaHeaderWinsOverTheStandardOne(): void
    {
        $response = new TransportResponse(429, '', ['x-ratelimit-retry-after' => '90', 'retry-after' => '5']);

        self::assertSame(90, $response->retryAfterSeconds());
    }

    public function testTheStandardHeaderIsTheFallback(): void
    {
        self::assertSame(120, (new TransportResponse(429, '', ['retry-after' => '120']))->retryAfterSeconds());
        self::assertSame(120, (new TransportResponse(429, '', ['x-ratelimit-retry-after' => '0', 'retry-after' => '120']))->retryAfterSeconds());
    }

    public function testRetryAfterOnlyParsesADelayInSeconds(): void
    {
        // An HTTP-date is valid in Retry-After but is not a number of seconds; do not guess.
        self::assertNull((new TransportResponse(429, '', ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']))->retryAfterSeconds());
        self::assertNull((new TransportResponse(429, '', ['retry-after' => '-5']))->retryAfterSeconds());
        self::assertNull((new TransportResponse(429, '', ['retry-after' => '1.5']))->retryAfterSeconds());
        self::assertNull((new TransportResponse(429, ''))->retryAfterSeconds());
    }

    // -- Redaction -------------------------------------------------------------------

    public function testRedactedMasksTheTokensOfATokenResponse(): void
    {
        $response = new TransportResponse(200, self::tokenBody(), ['content-type' => 'application/json']);

        $redacted = $response->redacted();

        self::assertStringNotContainsString(self::ACCESS, $redacted->body);
        self::assertStringNotContainsString(self::REFRESH, $redacted->body);

        $decoded = json_decode($redacted->body, true);
        self::assertIsArray($decoded, 'The redacted body is still valid JSON.');
        self::assertSame('[redacted]', $decoded['access_token']);
        self::assertSame('[redacted]', $decoded['refresh_token']);
        self::assertSame('Bearer', $decoded['token_type']);
        self::assertSame(2595600, $decoded['expires_in']);
        self::assertSame(200, $redacted->statusCode);
        self::assertSame($response->headers, $redacted->headers);
        self::assertStringContainsString(self::ACCESS, $response->body, 'The original is untouched.');
    }

    public function testRedactedKeepsTheCodeOfAnErrorBody(): void
    {
        // The error code is what a debug report is read for; only tokens are secrets here.
        $body = '{"errors":[{"path":"/packages/id/90014858123","message":"Carrier error","code":"carrierValidationError"}]}';

        self::assertSame($body, (new TransportResponse(400, $body))->redacted()->body);
    }

    public function testRedactedLeavesAPdfAlone(): void
    {
        // Every byte value, several times over, so a change that treats the body as text shows.
        $pdf = "%PDF-1.4\n" . str_repeat(implode('', array_map('chr', range(0, 255))), 8);

        self::assertSame($pdf, (new TransportResponse(200, $pdf, ['content-type' => 'application/pdf']))->redacted()->body);
    }

    public function testDumpsShowTheRedactedResponse(): void
    {
        $response = new TransportResponse(200, self::tokenBody(), ['content-type' => 'application/json']);

        ob_start();
        var_dump($response);
        $dump = (string) ob_get_clean();

        foreach ([$dump, print_r($response, true)] as $text) {
            self::assertStringNotContainsString(self::ACCESS, $text);
            self::assertStringNotContainsString(self::REFRESH, $text);
            self::assertStringContainsString('[redacted]', $text);
        }
    }
}
