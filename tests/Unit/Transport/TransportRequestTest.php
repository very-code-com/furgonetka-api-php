<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;

final class TransportRequestTest extends TestCase
{
    private const TOKEN = 'eyJ.access-token-value.sig';
    private const PASSWORD = 'p4ssw0rd-hunter2';

    // -- Validation --------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function badUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://example.com/x'];
        yield 'gopher scheme' => ['gopher://example.com/'];
        yield 'relative' => ['/packages'];
        yield 'no host' => ['https://'];
        yield 'empty' => [''];
    }

    #[DataProvider('badUrls')]
    public function testOnlyAbsoluteHttpUrlsAreAccepted(string $url): void
    {
        // Some URLs come out of API responses (document downloads); they must not reach cURL
        // as anything but http(s).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute http(s) URL');

        new TransportRequest($url);
    }

    public function testBothSchemesAreAccepted(): void
    {
        self::assertSame('http://127.0.0.1:8080/x', (new TransportRequest('http://127.0.0.1:8080/x'))->url);
        self::assertSame('https://api.furgonetka.pl/packages', (new TransportRequest('https://api.furgonetka.pl/packages'))->url);
    }

    public function testEverySupportedMethodIsAccepted(): void
    {
        foreach (TransportRequest::METHODS as $method) {
            self::assertSame($method, (new TransportRequest('https://x.test/', $method))->method);
        }
    }

    public function testAnUnsupportedMethodIsRejected(): void
    {
        foreach (['PATCH', 'get', 'HEAD', 'DELETE '] as $method) {
            try {
                new TransportRequest('https://x.test/', $method);
                self::fail("Expected '{$method}' to be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('unsupported method', $e->getMessage());
            }
        }
    }

    public function testAGetCannotHaveABody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GET request cannot have a body');

        new TransportRequest('https://x.test/', 'GET', '{}');
    }

    public function testTimeoutsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('timeouts must be at least 1 second');

        new TransportRequest('https://x.test/', timeout: 0);
    }

    public function testConnectTimeoutMustBePositiveToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TransportRequest('https://x.test/', connectTimeout: 0);
    }

    public function testAHeaderValueCannotSmuggleInAnotherHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a control character');

        new TransportRequest('https://x.test/', headers: ['X-Company-Id' => "12\r\nX-Injected: 1"]);
    }

    public function testAHeaderNameMustBeAToken(): void
    {
        // "X-Bad\n" is the case an unanchored `$` lets through: it matches before a final newline.
        foreach (['Bad Name', "X-Bad\r\nName", 'X:Bad', '', "X-Bad\n"] as $name) {
            try {
                new TransportRequest('https://x.test/', headers: [$name => 'v']);
                self::fail('Expected a header name to be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('not a valid header name', $e->getMessage());
            }
        }
    }

    // -- form() ------------------------------------------------------------------

    public function testFormBuildsAUrlEncodedPost(): void
    {
        $request = TransportRequest::form('https://api.furgonetka.pl/oauth/token', [
            'grant_type' => 'password',
            'scope' => 'api',
            'username' => 'shop+test@example.com',
            'password' => 'a b&c=d/é',
            'skipped' => null,
        ]);

        self::assertSame('POST', $request->method);
        self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        self::assertNotNull($request->body);

        // RFC 3986 encoding: a space is %20, so the body survives any strict form parser.
        self::assertStringContainsString('password=a%20b%26c%3Dd%2F%C3%A9', $request->body);
        self::assertStringNotContainsString('skipped', $request->body);

        parse_str($request->body, $fields);
        self::assertSame('shop+test@example.com', $fields['username']);
        self::assertSame('a b&c=d/é', $fields['password']);
    }

    public function testFormReplacesAnyContentTypeTheCallerGave(): void
    {
        $request = TransportRequest::form('https://x.test/', ['a' => '1'], ['content-type' => 'application/json', 'X-Extra' => 'y']);

        self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        self::assertSame('y', $request->header('X-Extra'));
        self::assertCount(2, $request->headers, 'No second Content-Type header.');
    }

    public function testFormPassesTimeoutsThrough(): void
    {
        $request = TransportRequest::form('https://x.test/', ['a' => '1'], timeout: 12, connectTimeout: 3);

        self::assertSame(12, $request->timeout);
        self::assertSame(3, $request->connectTimeout);
    }

    // -- header() and describe() ---------------------------------------------------

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new TransportRequest('https://x.test/', headers: ['Content-Type' => 'application/json']);

        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('application/json', $request->header('CONTENT-TYPE'));
        self::assertNull($request->header('accept'));
    }

    public function testDescribeLeavesTheQueryAndFragmentOut(): void
    {
        $request = new TransportRequest('https://api.furgonetka.pl/packages?filters[state]=waiting&sig=SECRET#frag', 'PUT');

        self::assertSame('PUT https://api.furgonetka.pl/packages', $request->describe());
        self::assertSame('GET https://x.test/plain', (new TransportRequest('https://x.test/plain'))->describe());
    }

    // -- redacted() ----------------------------------------------------------------

    public function testRedactedKeepsTheAuthSchemeAndHidesTheCredential(): void
    {
        $bearer = new TransportRequest('https://x.test/', headers: ['Authorization' => 'Bearer ' . self::TOKEN, 'Accept' => 'application/json']);
        $basic = new TransportRequest('https://x.test/', headers: ['authorization' => 'Basic Zm9vOmJhcg==']);

        self::assertSame('Bearer [redacted]', $bearer->redacted()->headers['Authorization']);
        self::assertSame('application/json', $bearer->redacted()->headers['Accept']);
        self::assertSame('Basic [redacted]', $basic->redacted()->headers['authorization']);
        self::assertSame('Bearer ' . self::TOKEN, $bearer->headers['Authorization'], 'The original is untouched.');
    }

    public function testRedactedHidesTheCredentialWhenThereIsNoScheme(): void
    {
        $request = new TransportRequest('https://x.test/', headers: ['Authorization' => 'opaque']);

        self::assertSame('[redacted]', $request->redacted()->headers['Authorization']);
    }

    public function testRedactedMasksSecretsInAFormBody(): void
    {
        $request = TransportRequest::form('https://x.test/oauth/token', [
            'grant_type' => 'password',
            'username' => 'shop@example.com',
            'password' => self::PASSWORD,
        ]);

        $redacted = $request->redacted();

        self::assertSame('grant_type=password&username=shop%40example.com&password=[redacted]', $redacted->body);
        self::assertSame($request->method, $redacted->method);
        self::assertSame($request->url, $redacted->url);
        self::assertSame($request->timeout, $redacted->timeout);
        self::assertStringContainsString(self::PASSWORD, (string) $request->body, 'The original is untouched.');
    }

    public function testRedactedMasksEverySecretFieldOfTheOAuthEndpoints(): void
    {
        $request = TransportRequest::form('https://x.test/oauth/2fa', [
            'refresh_token' => 'rt-1',
            'client_secret' => 'cs-1',
            'code' => 'auth-code-1',
            'twoFactorCode' => '123456',
            'uuid' => 'keep-me',
            'identifier' => 'keep@example.com',
        ]);

        $body = (string) $request->redacted()->body;

        foreach (['rt-1', 'cs-1', 'auth-code-1', '123456'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        self::assertStringContainsString('uuid=keep-me', $body);
        self::assertStringContainsString('identifier=keep%40example.com', $body);
    }

    public function testRedactedMasksSecretsInAJsonBody(): void
    {
        $request = new TransportRequest(
            'https://x.test/',
            'POST',
            '{"password": "' . self::PASSWORD . '", "service_id": "12"}',
            ['Content-Type' => 'application/json'],
        );

        self::assertSame('{"password": "[redacted]", "service_id": "12"}', $request->redacted()->body);
    }

    public function testRedactedTriesBothEncodingsWhenTheContentTypeIsMissing(): void
    {
        $form = new TransportRequest('https://x.test/', 'POST', 'password=' . self::PASSWORD);
        $json = new TransportRequest('https://x.test/', 'POST', '{"password":"' . self::PASSWORD . '"}');

        self::assertSame('password=[redacted]', $form->redacted()->body);
        self::assertSame('{"password":"[redacted]"}', $json->redacted()->body);
    }

    public function testRedactedLeavesAnUnrelatedBodyAlone(): void
    {
        $request = new TransportRequest('https://x.test/', 'POST', '{"receiver":{"name":"Anna"}}', ['Content-Type' => 'application/json']);

        self::assertSame('{"receiver":{"name":"Anna"}}', $request->redacted()->body);
        self::assertNull((new TransportRequest('https://x.test/'))->redacted()->body);
    }

    // -- Dumps and traces ------------------------------------------------------------

    public function testDumpsShowTheRedactedRequest(): void
    {
        $request = new TransportRequest(
            'https://x.test/oauth/token',
            'POST',
            'grant_type=password&password=' . self::PASSWORD,
            ['Authorization' => 'Basic Zm9vOmJhcg==', 'Content-Type' => 'application/x-www-form-urlencoded'],
        );

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean();

        foreach ([$dump, print_r($request, true)] as $text) {
            self::assertStringNotContainsString(self::PASSWORD, $text);
            self::assertStringNotContainsString('Zm9vOmJhcg==', $text);
            self::assertStringContainsString('Basic [redacted]', $text);
            self::assertStringContainsString('https://x.test/oauth/token', $text);
        }
    }

    public function testRedactedWithholdsAMultipartBodyWhole(): void
    {
        $body = "--b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.pdf\"\r\n\r\ninvoice-secret-\r\n--b--\r\n";
        $request = new TransportRequest('https://example.com/upload', 'POST', $body, ['Content-Type' => 'multipart/form-data; boundary=b', 'Authorization' => 'Bearer bearer-secret-1']);

        $redacted = $request->redacted();

        self::assertSame(sprintf('[multipart body withheld: %d bytes]', strlen($body)), $redacted->body);
        self::assertSame('Bearer [redacted]', $redacted->header('authorization'));
        self::assertSame($body, $request->body, 'The request itself is untouched.');
    }

    public function testAMultipartTypeIsRecognisedWhateverItsCase(): void
    {
        $request = new TransportRequest('https://example.com/upload', 'POST', 'secret-bytes', ['content-type' => 'Multipart/Form-Data; boundary=x']);

        self::assertStringStartsWith('[multipart body withheld:', (string) $request->redacted()->body);
    }

    public function testTheBodyDoesNotAppearInAStackTrace(): void
    {
        // A stack trace prints the first 15 characters of a string argument. The body of a token
        // request starts with a secret field in the OAuth flow, so those characters must not show.
        $body = 'code=' . self::PASSWORD;
        $leak = substr($body, 0, 15);

        try {
            // Fails inside the constructor, with the encoded body already built.
            TransportRequest::form('ftp://x.test/', ['code' => self::PASSWORD]);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($leak, $e->getTraceAsString());
        }

        try {
            new TransportRequest('ftp://x.test/', 'POST', $body);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($leak, $e->getTraceAsString());
        }
    }
}
