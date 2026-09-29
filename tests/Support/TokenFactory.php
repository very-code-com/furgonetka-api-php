<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthCredentials;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Builders for the objects the OAuth tests keep needing: a config, tokens that belong to it, and
 * answers from the token endpoint in the shapes Furgonetka documents.
 */
final class TokenFactory
{
    public const CLIENT_ID = 'client-1';
    public const CLIENT_SECRET = 'secret-1';
    public const REDIRECT_URI = 'http://localhost:8080/oauth/callback';

    /** The instant every test treats as "now", so that expiry arithmetic is exact. */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-28T12:00:00+00:00');
    }

    public static function config(bool $sandbox = true, bool $debug = false): FurgonetkaApiConfig
    {
        $credentials = new OAuthCredentials(self::CLIENT_ID, self::CLIENT_SECRET, self::REDIRECT_URI);

        return new FurgonetkaApiConfig(credentials: $credentials, sandbox: $sandbox, debug: $debug);
    }

    /**
     * A token that belongs to {@see self::config()}.
     *
     * @param int $expiresIn Seconds from {@see self::now()} until it expires.
     */
    public static function token(
        string $access = 'access-1',
        ?string $refresh = 'refresh-1',
        int $expiresIn = 3600,
        bool $sandbox = true,
        string $clientId = self::CLIENT_ID,
    ): AccessToken {
        return new AccessToken(
            accessToken: $access,
            expiresAt: self::now()->setTimestamp(self::now()->getTimestamp() + $expiresIn),
            refreshToken: $refresh,
            environment: $sandbox ? 'sandbox' : 'production',
            clientIdHash: hash('sha256', $clientId),
        );
    }

    /** The contents of a fixture from tests/Fixtures/oauth. */
    public static function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/oauth/' . $name . '.json');

        if ($body === false) {
            throw new \LogicException("Missing OAuth fixture: {$name}");
        }

        return $body;
    }

    /** A token endpoint answer with the given body. */
    public static function tokenResponse(string $access = 'access-2', ?string $refresh = 'refresh-2', int $expiresIn = 2678400): TransportResponse
    {
        $body = ['token_type' => 'Bearer', 'expires_in' => $expiresIn, 'access_token' => $access];

        if ($refresh !== null) {
            $body['refresh_token'] = $refresh;
        }

        return FakeTransport::jsonResponse(json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** An RFC 6749 error answer, as the token endpoint sends it. */
    public static function errorResponse(string $error, string $description = '', int $status = 400): TransportResponse
    {
        return FakeTransport::jsonResponse(
            json_encode(['error' => $error, 'error_description' => $description], JSON_THROW_ON_ERROR),
            $status,
        );
    }
}
