<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\PasswordReauthorizer;
use VeryCodeCom\FurgonetkaApi\Auth\ReauthorizerInterface;
use VeryCodeCom\FurgonetkaApi\Auth\Store\InMemoryTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\Store\TokenStoreInterface;
use VeryCodeCom\FurgonetkaApi\Auth\TokenManager;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\RecordingLogger;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class TokenManagerTest extends TestCase
{
    private FakeTransport $transport;
    private InMemoryTokenStore $store;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->store = new InMemoryTokenStore();
        $this->logger = new RecordingLogger();
    }

    private function oauth(): OAuthClient
    {
        return new OAuthClient(TokenFactory::config(), $this->transport, null, static fn (): \DateTimeImmutable => TokenFactory::now());
    }

    private function manager(?ReauthorizerInterface $reauthorizer = null, ?TokenStoreInterface $store = null, int $leeway = 300): TokenManager
    {
        return new TokenManager(
            $this->oauth(),
            $store ?? $this->store,
            $reauthorizer,
            $leeway,
            $this->logger,
            static fn (): \DateTimeImmutable => TokenFactory::now(),
        );
    }

    // -- The fast path ---------------------------------------------------------

    public function testAValidTokenIsReturnedWithoutTouchingTheNetwork(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 3600));

        $first = $this->manager()->accessToken();
        $second = $this->manager()->accessToken();

        self::assertSame('access-1', $first);
        self::assertSame('access-1', $second);
        self::assertSame([], $this->transport->requests);
    }

    public function testAValidTokenIsReadWithoutTakingTheLock(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 3600));
        $store = new class ($this->store) implements TokenStoreInterface {
            public int $locks = 0;

            public function __construct(private readonly InMemoryTokenStore $inner)
            {
            }

            public function load(): ?AccessToken
            {
                return $this->inner->load();
            }

            public function save(AccessToken $token): void
            {
                $this->inner->save($token);
            }

            public function synchronized(callable $callback): mixed
            {
                $this->locks++;

                return $callback();
            }
        };

        $this->manager(store: $store)->accessToken();

        self::assertSame(0, $store->locks);
    }

    // -- Refreshing ------------------------------------------------------------

    public function testAnExpiringTokenIsRefreshedAndTheRotatedPairIsSaved(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 299));
        $this->transport->push(TokenFactory::tokenResponse('access-2', 'refresh-2', 2678400));

        self::assertSame('access-2', $this->manager()->accessToken());

        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-1'], $this->transport->lastForm());
        $saved = $this->store->load();
        self::assertNotNull($saved);
        self::assertSame('access-2', $saved->accessToken);
        self::assertSame('refresh-2', $saved->refreshToken, 'The new refresh token must replace the spent one.');
        self::assertSame(TokenFactory::now()->getTimestamp() + 2678400, $saved->expiresAt->getTimestamp());
        self::assertTrue($this->logger->has('info', 'access token refreshed'));
    }

    public function testAnExpiredTokenIsRefreshedToo(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: -60));
        $this->transport->push(TokenFactory::tokenResponse('access-2'));

        self::assertSame('access-2', $this->manager()->accessToken());
    }

    public function testTheRefreshHappensOnceAndLaterCallsUseTheSavedToken(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 10));
        $this->transport->push(TokenFactory::tokenResponse('access-2', 'refresh-2'));

        $manager = $this->manager();

        $first = $manager->accessToken();
        $second = $manager->accessToken();
        $fromAnotherManager = $this->manager()->accessToken();

        self::assertSame('access-2', $first);
        self::assertSame('access-2', $second);
        self::assertSame('access-2', $fromAnotherManager, 'Another manager on the same store must reuse it as well.');
        self::assertCount(1, $this->transport->requests);
    }

    public function testTheLeewayDecidesHowEarlyTheTokenIsRefreshed(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 100));
        $this->transport->push(TokenFactory::tokenResponse('access-2'));

        self::assertSame('access-1', $this->manager(leeway: 60)->accessToken());
        self::assertSame('access-2', $this->manager(leeway: 100)->accessToken());
    }

    public function testTheLeewayCannotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager(leeway: -1);
    }

    public function testTheTokenIsReadAgainAfterTheLockAndAnotherProcessesRefreshIsUsed(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 10));
        $winner = TokenFactory::token('access-by-other-process', 'refresh-by-other-process', expiresIn: 3600);

        // While this manager waits for the lock, another process refreshes and saves.
        $store = new class ($this->store, $winner) implements TokenStoreInterface {
            public function __construct(private readonly InMemoryTokenStore $inner, private readonly AccessToken $winner)
            {
            }

            public function load(): ?AccessToken
            {
                return $this->inner->load();
            }

            public function save(AccessToken $token): void
            {
                $this->inner->save($token);
            }

            public function synchronized(callable $callback): mixed
            {
                $this->inner->save($this->winner);

                return $callback();
            }
        };

        self::assertSame('access-by-other-process', $this->manager(store: $store)->accessToken());
        self::assertSame([], $this->transport->requests, 'A second refresh would have spent the winner\'s refresh token.');
    }

    // -- Nothing to refresh with -----------------------------------------------

    public function testWithoutAStoredTokenTheManagerAsksForAConnection(): void
    {
        try {
            $this->manager()->accessToken();
            self::fail('Expected FurgonetkaApiReauthorizationRequiredException.');
        } catch (FurgonetkaApiReauthorizationRequiredException $e) {
            self::assertStringContainsString('No Furgonetka token is stored', $e->getMessage());
            self::assertStringContainsString('reauthorizer', $e->getMessage());
        }

        self::assertSame([], $this->transport->requests);
        self::assertTrue($this->logger->has('error', 'No Furgonetka token is stored'));
    }

    public function testAnExpiredTokenWithoutARefreshTokenAsksForAConnection(): void
    {
        $this->store->save(TokenFactory::token(refresh: null, expiresIn: -1));

        try {
            $this->manager()->accessToken();
            self::fail('Expected FurgonetkaApiReauthorizationRequiredException.');
        } catch (FurgonetkaApiReauthorizationRequiredException $e) {
            self::assertStringContainsString('no refresh token', $e->getMessage());
        }

        self::assertSame([], $this->transport->requests);
    }

    public function testARejectedRefreshTokenAsksForAConnectionAndKeepsTheCause(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 10));
        $this->transport->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400));

        try {
            $this->manager()->accessToken();
            self::fail('Expected FurgonetkaApiReauthorizationRequiredException.');
        } catch (FurgonetkaApiReauthorizationRequiredException $e) {
            self::assertSame('invalid_grant', $e->error);
            self::assertSame('The refresh token is invalid.', $e->errorDescription);
            self::assertSame(400, $e->statusCode);
            self::assertInstanceOf(FurgonetkaApiOAuthException::class, $e->getPrevious());
            self::assertStringContainsString('three months', $e->getMessage());
        }
    }

    public function testARejectedRefreshTokenDoesNotEraseTheStoredToken(): void
    {
        $token = TokenFactory::token(expiresIn: 10);
        $this->store->save($token);
        $this->transport->push(TokenFactory::errorResponse('invalid_grant', '', 400));

        try {
            $this->manager()->accessToken();
        } catch (FurgonetkaApiReauthorizationRequiredException) {
            // Expected.
        }

        self::assertSame($token, $this->store->load(), 'The store is the integrator\'s: only a save() or a refresh may change it.');
    }

    public function testOtherOAuthErrorsAreNotDisguisedAsAReauthorizationRequest(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 10));
        $this->transport->push(TokenFactory::errorResponse('invalid_client', 'Client authentication failed', 401));

        try {
            $this->manager(new PasswordReauthorizer('user', 'pw'))->accessToken();
            self::fail('Expected FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertNotInstanceOf(FurgonetkaApiReauthorizationRequiredException::class, $e);
            self::assertSame('invalid_client', $e->error);
        }

        self::assertCount(1, $this->transport->requests, 'Logging in again cannot fix wrong application keys, and must not be tried.');
    }

    public function testATransportFailureDuringTheRefreshPropagatesAndLeavesTheStoreAlone(): void
    {
        $token = TokenFactory::token(expiresIn: 10);
        $this->store->save($token);
        $failure = new FurgonetkaApiTransportException('Connection timed out');
        $this->transport->push($failure);

        try {
            $this->manager(new PasswordReauthorizer('user', 'pw'))->accessToken();
            self::fail('Expected the transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame($token, $this->store->load());
        self::assertCount(1, $this->transport->requests, 'A timeout is not proof that the refresh token is dead.');
    }

    // -- Foreign tokens --------------------------------------------------------

    public function testATokenFromAnotherEnvironmentIsIgnoredNotSentNorRefreshed(): void
    {
        $this->store->save(TokenFactory::token('production-access', 'production-refresh', expiresIn: 3600, sandbox: false));

        try {
            $this->manager()->accessToken();
            self::fail('Expected FurgonetkaApiReauthorizationRequiredException.');
        } catch (FurgonetkaApiReauthorizationRequiredException $e) {
            self::assertStringContainsString('another environment or application', $e->getMessage());
        }

        self::assertSame([], $this->transport->requests, 'Refreshing with the wrong keys would spend a refresh token that is not ours.');
        self::assertTrue($this->logger->has('warning', 'another environment or application'));
    }

    public function testATokenOfAnotherApplicationIsIgnoredEvenWhenItIsStillValid(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 3600, clientId: 'another-application'));

        $this->expectException(FurgonetkaApiReauthorizationRequiredException::class);

        $this->manager()->accessToken();
    }

    public function testAForeignTokenIsReplacedWhenAReauthorizerIsConfigured(): void
    {
        $this->store->save(TokenFactory::token('production-access', 'production-refresh', expiresIn: 3600, sandbox: false));
        $this->transport->push(TokenFactory::tokenResponse('sandbox-access', 'sandbox-refresh'));

        self::assertSame('sandbox-access', $this->manager(new PasswordReauthorizer('user', 'pw'))->accessToken());
        self::assertSame('sandbox-access', $this->store->load()?->accessToken);
        self::assertSame('password', $this->transport->lastForm()['grant_type']);
    }

    // -- Reauthorizer ----------------------------------------------------------

    public function testTheReauthorizerTakesOverWhenTheRefreshTokenIsRejected(): void
    {
        $this->store->save(TokenFactory::token(expiresIn: 10));
        $this->transport
            ->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400))
            ->push(TokenFactory::tokenResponse('access-relogin', 'refresh-relogin'));

        self::assertSame('access-relogin', $this->manager(new PasswordReauthorizer('shop@example.com', 'pw'))->accessToken());

        self::assertCount(2, $this->transport->requests);
        self::assertSame('password', $this->transport->lastForm()['grant_type']);
        self::assertSame('shop@example.com', $this->transport->lastForm()['username']);
        self::assertSame('refresh-relogin', $this->store->load()?->refreshToken);
        self::assertTrue($this->logger->has('warning', 'logging in again'));
    }

    public function testTheReauthorizerTakesOverWhenThereIsNoTokenAtAll(): void
    {
        $this->transport->push(TokenFactory::tokenResponse('access-first'));

        self::assertSame('access-first', $this->manager(new PasswordReauthorizer('user', 'pw'))->accessToken());
        self::assertCount(1, $this->transport->requests);
    }

    public function testTheReauthorizerTakesOverWhenTheExpiredTokenCannotBeRefreshed(): void
    {
        $this->store->save(TokenFactory::token(refresh: null, expiresIn: -1));
        $this->transport->push(TokenFactory::tokenResponse('access-relogin'));

        self::assertSame('access-relogin', $this->manager(new PasswordReauthorizer('user', 'pw'))->accessToken());
        self::assertSame('password', $this->transport->lastForm()['grant_type']);
    }

    public function testAFailingReauthorizerLeavesTheStoreAndItsErrorAlone(): void
    {
        $token = TokenFactory::token(expiresIn: 10);
        $this->store->save($token);
        $this->transport
            ->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400))
            ->push(TokenFactory::errorResponse('invalid_grant', 'The user credentials were incorrect.', 400));

        try {
            $this->manager(new PasswordReauthorizer('user', 'wrong'))->accessToken();
            self::fail('Expected FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('The user credentials were incorrect.', $e->errorDescription);
        }

        self::assertSame($token, $this->store->load());
    }

    public function testATokenThatTheReauthorizerBuiltForTheWrongEnvironmentIsRefused(): void
    {
        $rogue = new class implements ReauthorizerInterface {
            public function reauthorize(OAuthClient $oauth): AccessToken
            {
                return TokenFactory::token('rogue', sandbox: false);
            }
        };

        try {
            $this->manager($rogue)->accessToken();
            self::fail('Expected an UnexpectedValueException.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('not issued by this OAuthClient', $e->getMessage());
        }

        self::assertNull($this->store->load(), 'Saving it would make every later call log in again, one session each time.');
    }

    // -- invalidate() ----------------------------------------------------------

    public function testInvalidateMakesTheNextCallRefreshEvenThoughTheTokenIsNotExpiring(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 3600));
        $this->transport->push(TokenFactory::tokenResponse('access-2', 'refresh-2'));
        $manager = $this->manager();

        self::assertSame('access-1', $manager->accessToken());
        $manager->invalidate();

        self::assertSame('access-2', $manager->accessToken());
        self::assertSame('refresh-1', $this->transport->lastForm()['refresh_token']);
        self::assertSame('access-2', $manager->accessToken(), 'Only one refresh: the new token is not rejected.');
        self::assertCount(1, $this->transport->requests);
    }

    public function testInvalidateUsesTheTokenAnotherProcessAlreadyRefreshedTo(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 3600));
        $manager = $this->manager();
        $manager->accessToken();

        // Another process got its own 401 first, refreshed, and saved.
        $this->store->save(TokenFactory::token('access-2', 'refresh-2', expiresIn: 3600));
        $manager->invalidate();

        self::assertSame('access-2', $manager->accessToken());
        self::assertSame([], $this->transport->requests, 'Refreshing again would spend refresh-2 for nothing.');
    }

    public function testInvalidateBeforeAnyTokenWasIssuedDoesNothing(): void
    {
        $this->store->save(TokenFactory::token('access-1', expiresIn: 3600));
        $manager = $this->manager();

        $manager->invalidate();

        self::assertSame('access-1', $manager->accessToken());
        self::assertSame([], $this->transport->requests);
    }

    public function testInvalidateOfATokenThatCannotBeRefreshedAsksForAConnection(): void
    {
        $this->store->save(TokenFactory::token(refresh: null, expiresIn: 3600));
        $manager = $this->manager();
        $manager->accessToken();
        $manager->invalidate();

        try {
            $manager->accessToken();
            self::fail('Expected FurgonetkaApiReauthorizationRequiredException.');
        } catch (FurgonetkaApiReauthorizationRequiredException $e) {
            self::assertStringContainsString('expired or was refused', $e->getMessage());
        }
    }

    public function testASecondInvalidateAfterTheRetryRefreshesAgainOnlyIfTheNewTokenIsRefusedToo(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 3600));
        $this->transport
            ->push(TokenFactory::tokenResponse('access-2', 'refresh-2'))
            ->push(TokenFactory::tokenResponse('access-3', 'refresh-3'));
        $manager = $this->manager();

        $manager->accessToken();
        $manager->invalidate();
        self::assertSame('access-2', $manager->accessToken());
        $manager->invalidate();

        self::assertSame('access-3', $manager->accessToken());
        self::assertSame('refresh-2', $this->transport->lastForm()['refresh_token']);
    }

    // -- save() ----------------------------------------------------------------

    public function testSaveStoresATokenFromTheOAuthClient(): void
    {
        $token = TokenFactory::token('access-login', 'refresh-login');

        $this->manager()->save($token);

        self::assertSame($token, $this->store->load());
    }

    public function testSaveReplacesTheStoredToken(): void
    {
        $this->store->save(TokenFactory::token('old-access', 'old-refresh'));

        $this->manager()->save(TokenFactory::token('new-access', 'new-refresh'));

        self::assertSame('new-access', $this->manager()->accessToken());
    }

    public function testSaveRefusesATokenOfAnotherEnvironmentOrApplication(): void
    {
        $manager = $this->manager();

        foreach ([TokenFactory::token(sandbox: false), TokenFactory::token(clientId: 'another-application')] as $foreign) {
            try {
                $manager->save($foreign);
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('different environment or application', $e->getMessage());
            }
        }

        self::assertNull($this->store->load());
    }

    public function testSaveTakesTheLock(): void
    {
        $locks = 0;
        $store = new class ($this->store, $locks) implements TokenStoreInterface {
            public function __construct(private readonly InMemoryTokenStore $inner, private int &$locks)
            {
            }

            public function load(): ?AccessToken
            {
                return $this->inner->load();
            }

            public function save(AccessToken $token): void
            {
                $this->inner->save($token);
            }

            public function synchronized(callable $callback): mixed
            {
                $this->locks++;

                return $callback();
            }
        };

        $this->manager(store: $store)->save(TokenFactory::token());

        self::assertSame(1, $locks);
    }

    public function testSavingAFreshTokenForgetsAnEarlierRejection(): void
    {
        $this->store->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 3600));
        $manager = $this->manager();
        $manager->accessToken();
        $manager->invalidate();

        // The integrator reconnects; the same access token value coming back must not be treated as refused.
        $manager->save(TokenFactory::token('access-1', 'refresh-1', expiresIn: 3600));

        self::assertSame('access-1', $manager->accessToken());
        self::assertSame([], $this->transport->requests);
    }
}
