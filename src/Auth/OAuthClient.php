<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTwoFactorRequiredException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Internal\Json\ResponseParser;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;
use VeryCodeCom\FurgonetkaApi\Internal\Time\Clock;
use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * The four ways of getting a token from Furgonetka's OAuth2 server, and the 2FA step of one of
 * them.
 *
 * Grants, with what each is for:
 *  - authorization code ({@see self::authorizationUrl()}, {@see self::exchangeAuthorizationCode()}):
 *    connecting other people's accounts; refreshable.
 *  - password ({@see self::passwordGrant()}, {@see self::completeTwoFactor()}): a shop on its own
 *    account; refreshable.
 *  - refresh token ({@see self::refresh()}): what {@see TokenManager} does when a token runs out.
 *  - client credentials ({@see self::clientCredentials()}): calls that need no user; one hour,
 *    not refreshable.
 *
 * This class only talks to the token endpoint and returns what came back. It never stores
 * anything: keeping a token, refreshing it under a lock and recovering from a rejected refresh
 * token is the job of {@see TokenManager}, which is the single writer of the token store. Save a
 * token you obtained here with {@see TokenManager::save()}.
 *
 * Things about Furgonetka's OAuth server that shape this class:
 *  - Refresh tokens are single use. A refresh that succeeds on the server but whose answer is
 *    lost on the way leaves the caller with a dead token, so never retry a refresh blindly.
 *  - An application may have 20 open sessions per user; the 21st login silently ends the oldest.
 *    Every password grant is a new session, so it must not run per request.
 *  - The token endpoint answers errors in the RFC 6749 shape, not the shape of the API host, and
 *    those become {@see FurgonetkaApiOAuthException}.
 */
final class OAuthClient
{
    /** The only scope Furgonetka documents; it is also the default when none is sent. */
    public const SCOPE = 'api';

    /**
     * Lifetime assumed when a token response has no usable `expires_in`: one hour, the shortest
     * lifetime Furgonetka documents (client credentials). Too short only costs an early refresh,
     * while too long would keep a dead token in use.
     */
    public const DEFAULT_LIFETIME = 3600;

    private const PATH_AUTHORIZE = 'oauth/authorize';
    private const PATH_TOKEN = 'oauth/token';
    private const PATH_TWO_FACTOR = 'oauth/2fa';

    /** Random bytes in the `state` parameter; hex encoded it is 64 characters. */
    private const STATE_BYTES = 32;

    private readonly OAuthCredentials $credentials;
    private readonly LoggerInterface $logger;
    private readonly Clock $clock;
    private readonly ResponseParser $parser;

    /**
     * @param FurgonetkaApiConfig                   $config    Must carry the OAuth application keys.
     * @param TransportInterface                    $transport Sends the requests, see {@see \VeryCodeCom\FurgonetkaApi\Transport\CurlTransport}.
     * @param (\Closure(): \DateTimeImmutable)|null $clock     Source of "now" for token expiry; tests only.
     *
     * @throws \InvalidArgumentException when the config has no OAuth application keys.
     */
    public function __construct(
        private readonly FurgonetkaApiConfig $config,
        private readonly TransportInterface $transport,
        ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
    ) {
        $this->credentials = $config->credentials
            ?? throw new \InvalidArgumentException(
                'OAuthClient needs the OAuth application keys (Client ID and Client Secret) in the config.'
            );
        $this->logger = $logger ?? new NullLogger();
        $this->clock = new Clock($clock);
        $this->parser = new ResponseParser();
    }

    // -- Authorization code ----------------------------------------------------

    /**
     * Start the authorization code flow: build the URL of Furgonetka's consent page and a fresh
     * `state`. Nothing is sent; the user's browser makes the request.
     *
     * @throws \LogicException when the config has no redirect URI, which the flow requires and
     *         which must match the one registered with the application.
     */
    public function authorizationUrl(): AuthorizationRequest
    {
        $redirectUri = $this->redirectUri();
        $state = bin2hex(random_bytes(self::STATE_BYTES));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->credentials->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return new AuthorizationRequest($this->config->url(self::PATH_AUTHORIZE) . '?' . $query, $state);
    }

    /**
     * Finish the authorization code flow in your callback: check the `state`, then trade the
     * one-time `code` for a token pair. Do it right away, the code lives 30 seconds.
     *
     * @param string $code          The `code` query parameter of the callback.
     * @param string $state         The `state` query parameter of the callback.
     * @param string $expectedState What you kept from {@see self::authorizationUrl()}.
     *
     * @throws FurgonetkaApiOAuthException with error `state_mismatch` when the states differ or
     *         either is empty. An empty expected state is refused on purpose: a lost session
     *         would otherwise make an empty state in a forged callback look valid.
     * @throws \InvalidArgumentException when the code is empty.
     * @throws \LogicException when the config has no redirect URI.
     */
    public function exchangeAuthorizationCode(
        #[\SensitiveParameter]
        string $code,
        string $state,
        string $expectedState,
    ): AccessToken {
        $redirectUri = $this->redirectUri();

        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->fail(new FurgonetkaApiOAuthException(
                'Furgonetka OAuth authorization code exchange refused: the state of the callback does not match '
                . 'the one issued for this login (a forged or stale callback). The code was not used.',
                'state_mismatch',
            ));
        }

        if (trim($code) === '') {
            throw new \InvalidArgumentException('OAuthClient: the authorization code must not be empty.');
        }

        return $this->requestToken(
            self::PATH_TOKEN,
            [
                'grant_type' => 'authorization_code',
                // Furgonetka's documentation asks for the code to be URL-decoded before it is sent.
                'code' => urldecode($code),
                'redirect_uri' => $redirectUri,
            ],
            'authorization code exchange',
        );
    }

    // -- Password and 2FA ------------------------------------------------------

    /**
     * Log a Furgonetka account in with its username and password. Meant for a shop that connects
     * its own account once; anything that connects other people's accounts should use the
     * authorization code flow so that their passwords never reach it.
     *
     * Every call opens a new OAuth session and Furgonetka keeps at most 20 per user and
     * application, so do not call this per request.
     *
     * @throws FurgonetkaApiTwoFactorRequiredException when the account has 2FA turned on; finish
     *         with {@see self::completeTwoFactor()} within five minutes.
     * @throws FurgonetkaApiOAuthException when the credentials are wrong (`invalid_grant`) or the
     *         application keys are (`invalid_client`).
     * @throws \InvalidArgumentException when the username or the password is empty.
     */
    public function passwordGrant(
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): AccessToken {
        if (trim($username) === '') {
            throw new \InvalidArgumentException('OAuthClient: the username must not be empty.');
        }

        if ($password === '') {
            throw new \InvalidArgumentException('OAuthClient: the password must not be empty.');
        }

        return $this->requestToken(
            self::PATH_TOKEN,
            [
                'grant_type' => 'password',
                'scope' => self::SCOPE,
                'username' => $username,
                'password' => $password,
            ],
            'password grant',
        );
    }

    /**
     * Second half of a login that stopped at {@see FurgonetkaApiTwoFactorRequiredException}: send
     * the one-time code from the user's authenticator app together with the `hint` (the UUID of
     * the pending login) and the same username. There are five minutes for it; after that the
     * whole login starts again.
     *
     * @param string $hint          {@see FurgonetkaApiTwoFactorRequiredException::hint()}.
     * @param string $username      The same username as in the first step.
     * @param string $twoFactorCode The current code from the authenticator app.
     */
    public function completeTwoFactor(
        string $hint,
        string $username,
        #[\SensitiveParameter]
        string $twoFactorCode,
    ): AccessToken {
        if (trim($hint) === '' || trim($username) === '' || trim($twoFactorCode) === '') {
            throw new \InvalidArgumentException('OAuthClient: the hint, the username and the two-factor code must not be empty.');
        }

        return $this->requestToken(
            self::PATH_TWO_FACTOR,
            [
                'uuid' => $hint,
                'identifier' => $username,
                'twoFactorCode' => $twoFactorCode,
            ],
            'two-factor login',
        );
    }

    // -- Refresh and client credentials ----------------------------------------

    /**
     * Trade a refresh token for a new token pair. The refresh token is single use: after this
     * call it is spent, whatever happens to the answer, so the new pair has to be stored at once.
     * {@see TokenManager} does exactly that, under a lock; call this directly only when you
     * manage the storage yourself.
     *
     * @throws FurgonetkaApiOAuthException with error `invalid_grant` when the refresh token is
     *         expired (three months), already used or ended by newer sessions.
     */
    public function refresh(
        #[\SensitiveParameter]
        string $refreshToken,
    ): AccessToken {
        if ($refreshToken === '') {
            throw new \InvalidArgumentException('OAuthClient: the refresh token must not be empty.');
        }

        return $this->requestToken(
            self::PATH_TOKEN,
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            'token refresh',
        );
    }

    /**
     * A token for the application itself, with no user behind it. It lasts an hour and cannot be
     * refreshed; ask again when it expires. It gives no access to any user's shipments, so it is
     * of little use for this library beyond checking that the application keys are valid.
     *
     * The token is not something to put in the store that holds a user's token: it would replace it.
     */
    public function clientCredentials(): AccessToken
    {
        return $this->requestToken(
            self::PATH_TOKEN,
            [
                'grant_type' => 'client_credentials',
                'scope' => self::SCOPE,
            ],
            'client credentials grant',
        );
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * True when the token was issued for this client's environment and application, so it is
     * safe to use and to refresh with these keys.
     */
    public function isCompatible(AccessToken $token): bool
    {
        return $token->isIssuedFor($this->config->getEnvironment(), $this->credentials->clientIdHash());
    }

    // -- Internals -------------------------------------------------------------

    private function redirectUri(): string
    {
        return $this->credentials->redirectUri
            ?? throw new \LogicException(
                'The authorization code flow needs a redirect URI: set redirectUri in the config, identical to the one registered with the application.'
            );
    }

    /**
     * Send a form to a token endpoint and turn the answer into a token.
     *
     * @param array<string, string> $fields
     */
    private function requestToken(
        string $path,
        #[\SensitiveParameter]
        array $fields,
        string $operation,
    ): AccessToken {
        $request = TransportRequest::form(
            url: $this->config->url($path),
            fields: $fields,
            headers: [
                'Authorization' => $this->credentials->basicAuthorization(),
                'Accept' => 'application/json',
                FurgonetkaApiConfig::HEADER_LANGUAGE => $this->config->language->value,
            ],
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
        );

        try {
            $response = $this->transport->send($request);
        } catch (FurgonetkaApiTransportException $e) {
            $this->fail($e);
        }

        if (!$response->isSuccess()) {
            $this->fail($this->errorFrom($response, $operation), $response);
        }

        try {
            $token = $this->tokenFrom($this->parser->decode($response->body, "the {$operation}"), $operation);
        } catch (FurgonetkaApiResponseParseException $e) {
            $this->fail($e, $response);
        }

        $this->logger->info('Furgonetka: OAuth token obtained', [
            'grant' => $operation,
            'environment' => $token->environment,
            'expiresAt' => $token->expiresAt->format(\DATE_ATOM),
            'refreshable' => $token->hasRefreshToken(),
        ]);

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     * @throws FurgonetkaApiResponseParseException when the answer carries no access token.
     */
    private function tokenFrom(
        #[\SensitiveParameter]
        array $data,
        string $operation,
    ): AccessToken {
        $accessToken = Value::string($data, 'access_token')
            ?? throw new FurgonetkaApiResponseParseException("Furgonetka's {$operation} answer has no access_token.");

        $lifetime = Value::int($data, 'expires_in');

        if ($lifetime === null || $lifetime < 1) {
            $this->logger->warning('Furgonetka: OAuth answer has no usable expires_in, assuming a short lifetime', [
                'grant' => $operation,
                'assumedSeconds' => self::DEFAULT_LIFETIME,
            ]);
            $lifetime = self::DEFAULT_LIFETIME;
        }

        $now = $this->clock->now();

        return new AccessToken(
            accessToken: $accessToken,
            expiresAt: $now->setTimestamp($now->getTimestamp() + $lifetime),
            refreshToken: Value::string($data, 'refresh_token'),
            environment: $this->config->getEnvironment(),
            clientIdHash: $this->credentials->clientIdHash(),
        );
    }

    /**
     * Read an RFC 6749 error answer. The message carries the status, the error code and its
     * description, which are the parts that are safe to show: none of them holds a credential.
     */
    private function errorFrom(TransportResponse $response, string $operation): FurgonetkaApiOAuthException
    {
        try {
            $data = $this->parser->decode($response->body, "the {$operation}");
        } catch (FurgonetkaApiResponseParseException) {
            // A proxy's HTML page or an empty body: the status is all there is to report.
            $data = [];
        }

        $error = Value::string($data, 'error') ?? '';
        $description = Value::string($data, 'error_description') ?? Value::string($data, 'message') ?? '';
        $hint = Value::string($data, 'hint');

        $detail = $error;
        if ($description !== '') {
            $detail .= ($detail !== '' ? ': ' : '') . $description;
        }

        if ($error === '2fa_required' && $hint !== null) {
            $message = sprintf('Furgonetka OAuth %s needs a two-factor code (HTTP %d): %s', $operation, $response->statusCode, $detail);

            return new FurgonetkaApiTwoFactorRequiredException($message, $hint, $description, $response->statusCode);
        }

        // For every other error the hint is explanatory text ("Check the `code` parameter"); only
        // the 2FA hint is an identifier, and that one is handled above.
        if ($hint !== null) {
            $detail .= ' (' . $hint . ')';
        }

        $message = sprintf('Furgonetka OAuth %s failed (HTTP %d)', $operation, $response->statusCode)
            . ($detail !== '' ? ': ' . $detail : '.');

        return new FurgonetkaApiOAuthException($message, $error, $description, $response->statusCode);
    }

    /**
     * The one place an exception leaves this class: in debug mode the redacted raw answer is
     * attached and the full report is logged, otherwise only the message. A request for a
     * two-factor code is the first half of a login, not a failure, so it is logged as information.
     */
    private function fail(FurgonetkaApiException $exception, ?TransportResponse $response = null): never
    {
        if ($response !== null && $this->config->debug) {
            $exception->withRawResponse($response->redacted()->body);
        }

        $this->logger->log(
            $exception instanceof FurgonetkaApiTwoFactorRequiredException ? LogLevel::INFO : LogLevel::ERROR,
            $this->config->debug ? $exception->getDebugReport() : $exception->getMessage(),
        );

        throw $exception;
    }
}
