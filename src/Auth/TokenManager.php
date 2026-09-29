<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeryCodeCom\FurgonetkaApi\Auth\Store\TokenStoreInterface;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\Internal\Time\Clock;

/**
 * Keeps a Furgonetka session alive: hands out the current access token, refreshes it shortly
 * before it expires, and is the only writer of the token store.
 *
 * Why it is built the way it is:
 *  - Refresh tokens are single use and rotate, so two processes refreshing at once would leave
 *    one of them with a spent token and no session. The refresh therefore runs inside the
 *    store's lock, and the token is read a second time after the lock is taken: if another
 *    process refreshed while this one waited, its token is used and nothing is refreshed twice.
 *  - The new pair is saved before it is returned, so a crash right after the refresh cannot lose
 *    the only copy of the new refresh token.
 *  - A stored token that was issued for another environment or application is ignored, never
 *    sent and never refreshed. Using the wrong keys against a refresh token would spend it.
 *  - When the refresh token is rejected there are two ways out: the {@see ReauthorizerInterface}
 *    that the integrator opted into, or {@see FurgonetkaApiReauthorizationRequiredException}.
 *    The library never falls back to a password it was not explicitly given.
 *
 * Only the fast path is lock free: a valid token is read and returned without waiting for anyone.
 *
 * Furgonetka ends the old access token the moment a refresh succeeds (seen on the sandbox on
 * 2026-09-28: 401 right after). A request that was already in flight with the old token can
 * therefore be refused even though nothing is wrong, which is what {@see self::invalidate()} and
 * the client's single retry are for. Nothing is cached in memory: every call reads the store, so
 * a process always picks up the token another process refreshed.
 *
 * The manager holds no token in memory between calls. It remembers only a digest of the token it
 * last handed out (to recognise it in {@see self::invalidate()}), so dumping it reveals nothing.
 */
final class TokenManager implements TokenProviderInterface
{
    /** A token that expires within this many seconds counts as expiring and is refreshed. */
    public const DEFAULT_LEEWAY = 300;

    private readonly LoggerInterface $logger;
    private readonly Clock $clock;

    /** SHA-256 of the access token this manager returned last, or null before the first call. */
    private ?string $issuedDigest = null;

    /** SHA-256 of an access token that Furgonetka refused, so it must not be returned again. */
    private ?string $rejectedDigest = null;

    /**
     * @param OAuthClient                           $oauth        Source of refreshes; also says which tokens are ours.
     * @param TokenStoreInterface                   $store        Where the token lives between calls and processes.
     * @param ReauthorizerInterface|null            $reauthorizer Opt-in recovery when the refresh token is gone.
     * @param int                                   $leeway       Seconds before expiry at which a token is refreshed.
     * @param (\Closure(): \DateTimeImmutable)|null $clock        Source of "now"; tests only.
     */
    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly TokenStoreInterface $store,
        private readonly ?ReauthorizerInterface $reauthorizer = null,
        private readonly int $leeway = self::DEFAULT_LEEWAY,
        ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
    ) {
        if ($leeway < 0) {
            throw new \InvalidArgumentException('TokenManager: leeway must not be negative.');
        }

        $this->logger = $logger ?? new NullLogger();
        $this->clock = new Clock($clock);
    }

    /**
     * @throws FurgonetkaApiReauthorizationRequiredException when there is no usable token and no
     *         way to get one without a person.
     * @throws FurgonetkaApiOAuthException when Furgonetka refuses the refresh for any other reason,
     *         most likely wrong application keys (`invalid_client`).
     *
     * @phpstan-impure
     */
    public function accessToken(): string
    {
        $token = $this->compatible($this->store->load());

        if ($token !== null && !$this->needsRefresh($token)) {
            return $this->issue($token);
        }

        return $this->store->synchronized(function (): string {
            // Another process may have refreshed while this one waited for the lock.
            $stored = $this->store->load();
            $token = $this->compatible($stored);

            if ($token !== null && !$this->needsRefresh($token)) {
                return $this->issue($token);
            }

            $fresh = $this->renew($token, foreign: $stored !== null && $token === null);
            $this->store->save($fresh);

            return $this->issue($fresh);
        });
    }

    /**
     * The API refused the token this manager returned last: make the next call get another one.
     *
     * If the store already holds a different token (another process refreshed in the meantime),
     * that one is used and nothing is spent; only when the refused token is still the current one
     * is it refreshed. Calling this before any token was handed out does nothing.
     */
    public function invalidate(): void
    {
        if ($this->issuedDigest !== null) {
            $this->rejectedDigest = $this->issuedDigest;
        }
    }

    /**
     * Store a token obtained from {@see OAuthClient}, for instance right after the password
     * grant or the authorization code exchange. This replaces whatever token was stored, so it
     * is the way to connect an account, and to reconnect it after
     * {@see FurgonetkaApiReauthorizationRequiredException}.
     *
     * Do not save a client credentials token here: it belongs to no user and would replace the
     * user's token.
     *
     * @throws \InvalidArgumentException when the token was issued for another environment or application.
     */
    public function save(AccessToken $token): void
    {
        if (!$this->oauth->isCompatible($token)) {
            throw new \InvalidArgumentException(
                'TokenManager: the token was issued for a different environment or application than this client is configured for.'
            );
        }

        $this->store->synchronized(function () use ($token): void {
            $this->store->save($token);
        });

        $this->rejectedDigest = null;
    }

    /** True when the token is close to expiry or is the one Furgonetka just refused. */
    private function needsRefresh(AccessToken $token): bool
    {
        if ($this->rejectedDigest !== null && hash_equals($this->rejectedDigest, self::digest($token->accessToken))) {
            return true;
        }

        return $token->isExpiring($this->leeway, $this->clock->now());
    }

    /** The token if it belongs to this environment and application, otherwise null. */
    private function compatible(?AccessToken $token): ?AccessToken
    {
        if ($token === null || $this->oauth->isCompatible($token)) {
            return $token;
        }

        $this->logger->warning('Furgonetka: the stored token was issued for another environment or application and is ignored', [
            'storedEnvironment' => $token->environment,
        ]);

        return null;
    }

    /**
     * Get a new token when the current one cannot be used any more.
     *
     * @param bool $foreign True when a token is stored but belongs to another environment or
     *                      application, which changes the message when nothing can be done.
     */
    private function renew(?AccessToken $stale, bool $foreign): AccessToken
    {
        $rejection = null;

        if ($stale?->refreshToken !== null) {
            try {
                $fresh = $this->oauth->refresh($stale->refreshToken);
                $this->logger->info('Furgonetka: access token refreshed', ['expiresAt' => $fresh->expiresAt->format(\DATE_ATOM)]);

                return $fresh;
            } catch (FurgonetkaApiOAuthException $e) {
                // Anything but a dead refresh token (wrong keys, a server error) is not solved by
                // logging in again, and hiding it would send the integrator looking in the wrong place.
                if ($e->error !== 'invalid_grant') {
                    throw $e;
                }

                $rejection = $e;
            }
        }

        if ($this->reauthorizer !== null) {
            $this->logger->warning('Furgonetka: no usable refresh token, logging in again through the reauthorizer');

            $fresh = $this->reauthorizer->reauthorize($this->oauth);

            if (!$this->oauth->isCompatible($fresh)) {
                // Saving it would make every later call ignore it and log in again, one session each time.
                throw new \UnexpectedValueException(
                    'The reauthorizer returned a token that was not issued by this OAuthClient for this environment and application.'
                );
            }

            return $fresh;
        }

        $exception = $this->reauthorizationRequired($stale, $foreign, $rejection);
        $this->logger->error($exception->getMessage());

        throw $exception;
    }

    private function reauthorizationRequired(
        ?AccessToken $stale,
        bool $foreign,
        ?FurgonetkaApiOAuthException $rejection,
    ): FurgonetkaApiReauthorizationRequiredException {
        $fix = 'Connect the account again (password grant or authorization code flow), '
            . 'or opt in to automatic re-login with a reauthorizer.';

        if ($rejection !== null) {
            return new FurgonetkaApiReauthorizationRequiredException(
                'Furgonetka rejected the stored refresh token (it expired after three months, was already used, '
                . 'or was ended by newer sessions). ' . $fix,
                $rejection->error,
                $rejection->errorDescription,
                $rejection->statusCode,
                $rejection,
            );
        }

        if ($stale !== null) {
            return new FurgonetkaApiReauthorizationRequiredException(
                'The stored access token is expired or was refused, and it has no refresh token to renew it with. ' . $fix,
            );
        }

        return new FurgonetkaApiReauthorizationRequiredException(
            $foreign
                ? 'The stored token was issued for another environment or application, and there is none for this one. ' . $fix
                : 'No Furgonetka token is stored. ' . $fix,
        );
    }

    /** Remember which token is being handed out, and forget a rejection once a different one is. */
    private function issue(AccessToken $token): string
    {
        $digest = self::digest($token->accessToken);

        if ($this->rejectedDigest !== null && !hash_equals($this->rejectedDigest, $digest)) {
            $this->rejectedDigest = null;
        }

        $this->issuedDigest = $digest;

        return $token->accessToken;
    }

    private static function digest(
        #[\SensitiveParameter]
        string $accessToken,
    ): string {
        return hash('sha256', $accessToken);
    }
}
