<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\ReauthorizerInterface;
use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Auth\Store\InMemoryTokenStore;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

/**
 * The client built by `create()` with a token store: logging in, keeping the tokens, and the
 * refresh that follows a refused token. The transport is scripted, so the token manager, the OAuth
 * client and the API client all run for real on top of it.
 */
final class FurgonetkaApiClientConnectTest extends TestCase
{
    private const PASSWORD = 'a-password-of-15c';

    /** A token that is valid on the real clock, which `create()` uses. */
    private static function freshToken(string $access, string $refresh): AccessToken
    {
        return new AccessToken(
            accessToken: $access,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            refreshToken: $refresh,
            environment: 'sandbox',
            clientIdHash: hash('sha256', TokenFactory::CLIENT_ID),
        );
    }

    private static function authorizationOf(FakeTransport $transport, int $index): ?string
    {
        return $transport->requests[$index]->header('Authorization');
    }

    public function testAPasswordLoginKeepsTheTokensAndTheNextCallUsesThem(): void
    {
        $transport = new FakeTransport([TokenFactory::tokenResponse('access-new', 'refresh-new'), Fixtures::response('account/companies')]);
        $store = new InMemoryTokenStore();
        $client = FurgonetkaApiClient::create(TokenFactory::config(), $store, transport: $transport);

        $client->connectWithPassword('user@example.com', self::PASSWORD);
        $companies = $client->listCompanies();

        self::assertCount(1, $companies);
        self::assertSame('Bearer access-new', self::authorizationOf($transport, 1));
        self::assertSame('refresh-new', $store->load()?->refreshToken);
        self::assertStringContainsString('/oauth/token', $transport->requests[0]->url);
    }

    public function testATwoFactorLoginIsFinishedWithTheHintAndTheCode(): void
    {
        $transport = new FakeTransport([TokenFactory::tokenResponse('access-2fa', 'refresh-2fa'), Fixtures::response('account/companies')]);
        $store = new InMemoryTokenStore();
        $client = FurgonetkaApiClient::create(TokenFactory::config(), $store, transport: $transport);

        $client->connectWithTwoFactor('hint-from-the-first-step', 'user@example.com', '123456');
        $client->listCompanies();

        self::assertSame('Bearer access-2fa', self::authorizationOf($transport, 1));
        self::assertSame('access-2fa', $store->load()?->accessToken);
    }

    public function testTheAuthorizationCodeIsExchangedAndKept(): void
    {
        $transport = new FakeTransport([TokenFactory::tokenResponse('access-code', 'refresh-code'), Fixtures::response('account/companies')]);
        $store = new InMemoryTokenStore();
        $client = FurgonetkaApiClient::create(TokenFactory::config(), $store, transport: $transport);

        $client->connectWithAuthorizationCode('the-code-from-the-callback', 'state-1', 'state-1');
        $client->listCompanies();

        self::assertSame('Bearer access-code', self::authorizationOf($transport, 1));
    }

    public function testACallbackWithAnotherStateIsRefusedAndNothingIsSent(): void
    {
        $transport = new FakeTransport();
        $store = new InMemoryTokenStore();
        $client = FurgonetkaApiClient::create(TokenFactory::config(), $store, transport: $transport);

        try {
            $client->connectWithAuthorizationCode('the-code-from-the-callback', 'forged', 'state-1');
            self::fail('Expected the state check to refuse.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('state_mismatch', $e->error);
        }

        self::assertSame([], $transport->requests, 'The code must not leave the machine when the state is wrong.');
        self::assertNull($store->load());
    }

    public function testAClientBuiltWithoutAStoreCannotLogIn(): void
    {
        $client = FurgonetkaApiClient::create(TokenFactory::config(), new StaticTokenProvider('t'), transport: new FakeTransport());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('keeps no tokens');

        $client->connectWithPassword('user@example.com', self::PASSWORD);
    }

    public function testTheOAuthClientIsAvailableWhenTheConfigHasTheKeys(): void
    {
        $client = FurgonetkaApiClient::create(TokenFactory::config(), transport: new FakeTransport());

        self::assertInstanceOf(OAuthClient::class, $client->oauth());
    }

    // -- The refusal of a token, with the real token manager ------------------------------------------------

    public function testARefusedTokenIsRefreshedAndTheRequestRepeatedWithTheNewOne(): void
    {
        $transport = new FakeTransport([
            Fixtures::response('errors/unauthorized-bad-token', 401),
            TokenFactory::tokenResponse('access-refreshed', 'refresh-2'),
            Fixtures::response('account/companies'),
        ]);
        $store = new InMemoryTokenStore(self::freshToken('access-old', 'refresh-1'));
        $client = FurgonetkaApiClient::create(TokenFactory::config(), $store, transport: $transport);

        $companies = $client->listCompanies();

        self::assertCount(1, $companies);
        self::assertSame('Bearer access-old', self::authorizationOf($transport, 0));
        self::assertStringContainsString('/oauth/token', $transport->requests[1]->url);
        self::assertSame('Bearer access-refreshed', self::authorizationOf($transport, 2));
        self::assertSame('refresh-2', $store->load()?->refreshToken, 'The refresh token rotated and the new one is kept.');
    }

    public function testAValidStoredTokenIsUsedWithoutAnyLoginOrRefresh(): void
    {
        $transport = new FakeTransport([Fixtures::response('account/companies')]);
        $client = FurgonetkaApiClient::create(TokenFactory::config(), new InMemoryTokenStore(self::freshToken('access-old', 'refresh-1')), transport: $transport);

        $client->listCompanies();

        self::assertCount(1, $transport->requests);
        self::assertSame('Bearer access-old', self::authorizationOf($transport, 0));
    }

    // -- create() ---------------------------------------------------------------------------------------------------

    public function testAReauthorizerWithoutAStoreIsRefused(): void
    {
        $reauthorizer = new class implements ReauthorizerInterface {
            public function reauthorize(OAuthClient $oauth): AccessToken
            {
                throw new \LogicException('Not expected to be called.');
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pass a store');

        FurgonetkaApiClient::create(TokenFactory::config(), new StaticTokenProvider('t'), transport: new FakeTransport(), reauthorizer: $reauthorizer);
    }

    public function testAClientWithoutKeysStillServesThePublicEndpoints(): void
    {
        $transport = new FakeTransport([Fixtures::response('account/dictionary')]);
        $client = FurgonetkaApiClient::create(FurgonetkaApiConfig::sandbox(), transport: $transport);

        self::assertNotEmpty($client->fetchDictionary()->carriers());
        self::assertNull(self::authorizationOf($transport, 0));

        $this->expectException(\LogicException::class);

        $client->oauth();
    }
}
