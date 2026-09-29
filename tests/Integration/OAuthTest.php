<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthCredentials;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\TokenManager;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * The OAuth flows against the real sandbox: what the unit tests script, checked for real.
 *
 * The one test that opens a password session of its own is in the group `oauth-grant`: the API keeps
 * 20 sessions per application and user, so a routine CI run leaves it out (`--exclude-group oauth-grant`)
 * and only the weekly and the manual run include it.
 *
 * Not covered: the two-factor login. The sandbox account has no 2FA (with it the password grant
 * would answer `2fa_required` and the nightly run could never pass), so that path is only
 * exercised against the documented response shape.
 */
final class OAuthTest extends SandboxTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/furgonetka-oauth-it-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->directory);
        }
    }

    private function oauth(?FurgonetkaApiConfig $config = null): OAuthClient
    {
        return new OAuthClient($config ?? $this->config(), $this->transport());
    }

    /** An authenticated call to a cheap read-only endpoint, to see whether a token is accepted. */
    private function callApi(AccessToken|string $token): TransportResponse
    {
        return $this->transport()->send(new TransportRequest(
            url: $this->config()->url('account/services'),
            headers: [
                'Authorization' => 'Bearer ' . ($token instanceof AccessToken ? $token->accessToken : $token),
                'Accept' => 'application/vnd.furgonetka.v1+json',
                'X-Language' => 'en_GB',
            ],
        ));
    }

    #[Group('oauth-grant')]
    public function testPasswordGrantThenRefreshRotatesTheSessionAndSpendsTheOldRefreshToken(): void
    {
        $oauth = $this->oauth();
        $store = new FileTokenStore($this->directory . '/token.json');

        $first = $oauth->passwordGrant($this->env('FURGONETKA_USERNAME'), $this->env('FURGONETKA_PASSWORD'));

        self::assertTrue($first->hasRefreshToken());
        self::assertSame('sandbox', $first->environment);
        // 30 days plus an hour on the sandbox (2595600 s); the documentation says 30 days.
        self::assertEqualsWithDelta(30 * 86400, $first->expiresAt->getTimestamp() - time(), 7200);
        self::assertSame(200, $this->callApi($first)->statusCode);

        (new TokenManager($oauth, $store))->save($first);

        // Move the clock past the expiry so that the manager has to refresh.
        $later = new \DateTimeImmutable('+31 days');
        $renewed = (new TokenManager($oauth, $store, clock: static fn (): \DateTimeImmutable => $later))->accessToken();
        $second = $store->load();

        self::assertNotNull($second);
        self::assertSame($renewed, $second->accessToken);
        self::assertNotSame($first->accessToken, $second->accessToken);
        self::assertNotSame($first->refreshToken, $second->refreshToken, 'Refresh tokens rotate.');
        self::assertSame(200, $this->callApi($second)->statusCode);

        // Found on 2026-09-28: the old access token stops working the moment a refresh succeeds,
        // so a request that was in flight with it gets a 401. That is what invalidate() is for.
        self::assertSame(401, $this->callApi($first)->statusCode);

        try {
            $oauth->refresh((string) $first->refreshToken);
            self::fail('The spent refresh token must be rejected.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
            self::assertSame(400, $e->statusCode);
        }

        // A store that still holds the spent token: the manager must ask for a new connection.
        $dead = new FileTokenStore($this->directory . '/dead.json');
        $dead->save(new AccessToken('spent.access.token', $later->modify('-1 day'), $first->refreshToken, 'sandbox', $first->clientIdHash));

        $this->expectException(FurgonetkaApiReauthorizationRequiredException::class);

        (new TokenManager($oauth, $dead, clock: static fn (): \DateTimeImmutable => $later))->accessToken();
    }

    public function testWrongUserCredentialsAreAnInvalidGrant(): void
    {
        try {
            $this->oauth()->passwordGrant($this->env('FURGONETKA_USERNAME'), 'this-is-not-the-password');
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
            self::assertSame(400, $e->statusCode);
        }
    }

    public function testUnknownApplicationKeysAreAnInvalidClient(): void
    {
        $config = new FurgonetkaApiConfig(credentials: new OAuthCredentials('not-an-application', 'not-a-secret'), sandbox: true, debug: true);

        try {
            $this->oauth($config)->clientCredentials();
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('invalid_client', $e->error);
            self::assertSame(401, $e->statusCode);
        }
    }

    public function testClientCredentialsGiveAnHourLongTokenWithoutARefreshToken(): void
    {
        $token = $this->oauth()->clientCredentials();

        self::assertFalse($token->hasRefreshToken());
        self::assertEqualsWithDelta(3600, $token->expiresAt->getTimestamp() - time(), 120);
        // An application token belongs to no user, so the user's endpoints refuse it.
        self::assertSame(401, $this->callApi($token)->statusCode);
    }

    public function testTheAuthorizationUrlLeadsToTheSandboxLoginPage(): void
    {
        if ($this->env('FURGONETKA_REDIRECT_URI') === '') {
            self::markTestSkipped('FURGONETKA_REDIRECT_URI is not set.');
        }

        $request = $this->oauth()->authorizationUrl();
        $response = $this->transport()->send(new TransportRequest($request->url, headers: ['Accept' => 'text/html']));

        // An anonymous visitor is sent to log in first; the consent page comes after that.
        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('sandbox.furgonetka.pl', (string) $response->header('location'));
    }
}
