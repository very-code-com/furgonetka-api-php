<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthCredentials;

final class OAuthCredentialsTest extends TestCase
{
    /** 15 characters, so a leak into a stack trace (which cuts arguments to 15) would show whole. */
    private const SECRET = 'cred-secret-51c';

    public function testBasicAuthorizationIsBase64OfIdColonSecret(): void
    {
        $credentials = new OAuthCredentials('client-1', 'secret-1');

        // The format from Furgonetka's OAuth documentation: Base64 of client_id:client_secret.
        self::assertSame('Basic ' . base64_encode('client-1:secret-1'), $credentials->basicAuthorization());
        self::assertSame('Basic Y2xpZW50LTE6c2VjcmV0LTE=', $credentials->basicAuthorization());
    }

    public function testTheRedirectUriIsOptional(): void
    {
        self::assertNull((new OAuthCredentials('id', 's'))->redirectUri);
        self::assertSame('http://localhost:8080/oauth/callback', (new OAuthCredentials('id', 's', 'http://localhost:8080/oauth/callback'))->redirectUri);
    }

    public function testTheClientIdHashIsTheSha256OfTheIdAlone(): void
    {
        $one = new OAuthCredentials('client-1', 'secret-a');
        $two = new OAuthCredentials('client-1', 'a-rotated-secret');

        self::assertSame(hash('sha256', 'client-1'), $one->clientIdHash());
        self::assertSame($one->clientIdHash(), $two->clientIdHash(), 'A new secret must not orphan the stored tokens.');
        self::assertNotSame($one->clientIdHash(), (new OAuthCredentials('client-2', 'secret-a'))->clientIdHash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $one->clientIdHash());
    }

    /**
     * @return iterable<string, array{string, string, ?string, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'blank id' => ['  ', 's', null, 'clientId must not be empty'];
        yield 'colon in id' => ['a:b', 's', null, 'clientId must not contain a colon'];
        yield 'blank secret' => ['id', '', null, 'clientSecret must not be empty'];
        yield 'blank redirect' => ['id', 's', ' ', 'redirectUri must not be an empty string'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testInvalidInputIsRejected(string $id, string $secret, ?string $redirect, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new OAuthCredentials($id, $secret, $redirect);
    }

    public function testTheSecretDoesNotAppearInDumps(): void
    {
        $credentials = new OAuthCredentials('the-id', self::SECRET, 'http://localhost/cb');

        ob_start();
        var_dump($credentials);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringNotContainsString(self::SECRET, print_r($credentials, true));
        self::assertStringContainsString('the-id', $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    public function testTheSecretDoesNotAppearInAStackTrace(): void
    {
        try {
            new OAuthCredentials('bad:id', self::SECRET);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
        }
    }
}
