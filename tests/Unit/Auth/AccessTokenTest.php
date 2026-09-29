<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class AccessTokenTest extends TestCase
{
    /** 15 characters, so that a leak into a stack trace (which cuts arguments to 15) would show whole. */
    private const ACCESS = 'access-secret-1';
    private const REFRESH = 'refresh-secret2';

    public function testItRoundTripsThroughItsStoredForm(): void
    {
        $token = TokenFactory::token(self::ACCESS, self::REFRESH, expiresIn: 7200);

        $copy = AccessToken::fromArray($token->toArray());

        self::assertSame(self::ACCESS, $copy->accessToken);
        self::assertSame(self::REFRESH, $copy->refreshToken);
        self::assertSame($token->expiresAt->getTimestamp(), $copy->expiresAt->getTimestamp());
        self::assertSame('sandbox', $copy->environment);
        self::assertSame($token->clientIdHash, $copy->clientIdHash);
    }

    public function testATokenWithoutARefreshTokenRoundTripsAsNull(): void
    {
        $copy = AccessToken::fromArray(TokenFactory::token(refresh: null)->toArray());

        self::assertNull($copy->refreshToken);
        self::assertFalse($copy->hasRefreshToken());
    }

    public function testTheStoredFormIsJsonSafeAndVersioned(): void
    {
        $stored = TokenFactory::token()->toArray();

        self::assertSame(1, $stored['version']);
        self::assertSame('2026-09-28T13:00:00+00:00', $stored['expires_at']);
        self::assertSame($stored, json_decode(json_encode($stored, JSON_THROW_ON_ERROR), true));
    }

    public function testTheOffsetOfTheExpiryIsKept(): void
    {
        $token = new AccessToken('a', new \DateTimeImmutable('2026-09-28T14:00:00+02:00'), null, 'production', hash('sha256', 'x'));

        self::assertSame('2026-09-28T14:00:00+02:00', $token->toArray()['expires_at']);
        self::assertSame(
            (new \DateTimeImmutable('2026-09-28T12:00:00+00:00'))->getTimestamp(),
            AccessToken::fromArray($token->toArray())->expiresAt->getTimestamp(),
        );
    }

    /**
     * @return iterable<string, array{int, int, bool}> seconds until expiry, leeway, expected
     */
    public static function expiryCases(): iterable
    {
        yield 'far from expiry' => [3600, 300, false];
        yield 'one second outside the leeway' => [301, 300, false];
        yield 'exactly at the leeway' => [300, 300, true];
        yield 'inside the leeway' => [299, 300, true];
        yield 'at the expiry instant' => [0, 0, true];
        yield 'one second before expiry, no leeway' => [1, 0, false];
        yield 'already expired' => [-10, 0, true];
        yield 'already expired, with leeway' => [-10, 300, true];
    }

    #[DataProvider('expiryCases')]
    public function testIsExpiringHonoursTheLeeway(int $expiresIn, int $leeway, bool $expected): void
    {
        self::assertSame($expected, TokenFactory::token(expiresIn: $expiresIn)->isExpiring($leeway, TokenFactory::now()));
    }

    public function testIsIssuedForMatchesEnvironmentAndApplication(): void
    {
        $token = TokenFactory::token();
        $hash = hash('sha256', TokenFactory::CLIENT_ID);

        self::assertTrue($token->isIssuedFor('sandbox', $hash));
        self::assertFalse($token->isIssuedFor('production', $hash));
        self::assertFalse($token->isIssuedFor('sandbox', hash('sha256', 'another-application')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidStoredData(): iterable
    {
        $valid = [
            'version' => 1,
            'access_token' => 'a',
            'refresh_token' => null,
            'expires_at' => '2026-09-28T13:00:00+00:00',
            'environment' => 'sandbox',
            'client_id_hash' => str_repeat('a', 64),
        ];

        yield 'no version' => [array_diff_key($valid, ['version' => 1]), 'format version'];
        yield 'a version from the future' => [['version' => 2] + $valid, 'format version'];
        yield 'access token missing' => [array_diff_key($valid, ['access_token' => 1]), 'tokens must be strings'];
        yield 'access token not a string' => [['access_token' => 42] + $valid, 'tokens must be strings'];
        yield 'refresh token not a string' => [['refresh_token' => 42] + $valid, 'tokens must be strings'];
        yield 'empty access token' => [['access_token' => ''] + $valid, 'must not be empty'];
        yield 'empty refresh token' => [['refresh_token' => ''] + $valid, 'use null'];
        yield 'expiry missing' => [array_diff_key($valid, ['expires_at' => 1]), 'must be strings'];
        yield 'expiry is not a date' => [['expires_at' => 'tomorrow'] + $valid, 'ISO 8601'];
        yield 'expiry without an offset' => [['expires_at' => '2026-09-28 13:00:00'] + $valid, 'ISO 8601'];
        yield 'unknown environment' => [['environment' => 'staging'] + $valid, 'sandbox" or "production'];
        yield 'environment missing' => [array_diff_key($valid, ['environment' => 1]), 'must be strings'];
        yield 'hash is not a digest' => [['client_id_hash' => 'client-1'] + $valid, 'SHA-256'];
        yield 'hash in upper case' => [['client_id_hash' => str_repeat('A', 64)] + $valid, 'SHA-256'];
        yield 'hash with a trailing newline' => [['client_id_hash' => str_repeat('a', 64) . "\n"] + $valid, 'SHA-256'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidStoredData')]
    public function testFromArrayRejectsAnythingThatIsNotATokenOfTheCurrentFormat(array $data, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        AccessToken::fromArray($data);
    }

    public function testTheTokensDoNotAppearInDumps(): void
    {
        $token = TokenFactory::token(self::ACCESS, self::REFRESH);

        ob_start();
        var_dump($token);
        $dump = (string) ob_get_clean();

        foreach ([$dump, print_r($token, true)] as $output) {
            self::assertStringNotContainsString(self::ACCESS, $output);
            self::assertStringNotContainsString(self::REFRESH, $output);
            self::assertStringContainsString('[redacted]', $output);
            self::assertStringContainsString('sandbox', $output);
        }
    }

    public function testTheDebugInfoOfATokenWithoutARefreshTokenShowsNullNotAMask(): void
    {
        $info = TokenFactory::token(self::ACCESS, null)->__debugInfo();

        self::assertNull($info['refreshToken']);
        self::assertSame('[redacted]', $info['accessToken']);
    }

    public function testTheTokensDoNotAppearInAStackTrace(): void
    {
        try {
            new AccessToken(self::ACCESS, TokenFactory::now(), self::REFRESH, 'staging', hash('sha256', 'x'));
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::ACCESS, $e->getTraceAsString() . print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::REFRESH, $e->getTraceAsString() . print_r($e->getTrace(), true));
        }
    }

    public function testTheTokensDoNotAppearInAStackTraceOfFromArray(): void
    {
        try {
            AccessToken::fromArray([
                'version' => 1,
                'access_token' => self::ACCESS,
                'refresh_token' => self::REFRESH,
                'expires_at' => 'not a date',
                'environment' => 'sandbox',
                'client_id_hash' => str_repeat('a', 64),
            ]);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::ACCESS, $e->getTraceAsString() . print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::REFRESH, $e->getTraceAsString() . print_r($e->getTrace(), true));
        }
    }
}
