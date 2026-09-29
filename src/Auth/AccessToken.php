<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

/**
 * A token pair issued by Furgonetka, plus what the library needs to manage it safely.
 *
 * Besides the access token, its expiry and the refresh token (absent for the client credentials
 * grant), a token remembers the environment and the application it was issued for. A token store
 * outlives configuration changes, and a sandbox token that reached production, or a token of
 * another application that was left in the same file, would fail in confusing ways or, worse,
 * make the library burn a session refreshing a token that is not its own. The token manager
 * ignores a token that does not match the current configuration.
 *
 * Both tokens are secrets. They are marked sensitive so a stack trace cannot show them,
 * `var_dump()` and `print_r()` mask them, and there is no `__toString()`. The only way to get
 * them into storage is the explicit {@see self::toArray()}.
 */
final class AccessToken
{
    /** Version of the stored form, so a later change to it can be recognised instead of misread. */
    public const FORMAT_VERSION = 1;

    private const ENVIRONMENTS = ['sandbox', 'production'];

    /**
     * @param string                 $accessToken  The bearer token sent with API requests.
     * @param \DateTimeImmutable     $expiresAt    When the access token stops being valid.
     * @param string|null            $refreshToken Single-use token that buys the next pair. Null
     *                                             for the client credentials grant, which cannot
     *                                             be refreshed.
     * @param string                 $environment  `sandbox` or `production`.
     * @param string                 $clientIdHash SHA-256 of the Client ID of the issuing
     *                                             application, see {@see OAuthCredentials::clientIdHash()}.
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $accessToken,
        public readonly \DateTimeImmutable $expiresAt,
        #[\SensitiveParameter]
        public readonly ?string $refreshToken,
        public readonly string $environment,
        public readonly string $clientIdHash,
    ) {
        if ($accessToken === '') {
            throw new \InvalidArgumentException('AccessToken: the access token must not be empty.');
        }

        if ($refreshToken === '') {
            throw new \InvalidArgumentException('AccessToken: the refresh token must not be an empty string, use null.');
        }

        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('AccessToken: the environment must be "sandbox" or "production".');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $clientIdHash) !== 1) {
            throw new \InvalidArgumentException('AccessToken: the client id hash must be a hex SHA-256 digest.');
        }
    }

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== null;
    }

    /**
     * True when the access token is expired or will be within the leeway. Refreshing a little
     * early keeps a request that is already on its way from arriving with a token that expires
     * in transit.
     *
     * @param int $leewaySeconds How long before the real expiry the token counts as expiring.
     */
    public function isExpiring(int $leewaySeconds, \DateTimeImmutable $now): bool
    {
        return $this->expiresAt->getTimestamp() - $leewaySeconds <= $now->getTimestamp();
    }

    /** True when the token was issued for this environment and this application. */
    public function isIssuedFor(string $environment, string $clientIdHash): bool
    {
        return $this->environment === $environment && hash_equals($this->clientIdHash, $clientIdHash);
    }

    /**
     * The stored form: plain scalars, ready for `json_encode()`. **This contains both tokens in
     * clear text**, so it belongs in a store the application controls and nowhere else, never in
     * a log or an error message.
     *
     * @return array{version: int, access_token: string, refresh_token: string|null, expires_at: string, environment: string, client_id_hash: string}
     */
    public function toArray(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt->format(\DATE_ATOM),
            'environment' => $this->environment,
            'client_id_hash' => $this->clientIdHash,
        ];
    }

    /**
     * Rebuild a token from {@see self::toArray()}. Strict, because the data comes from a store
     * that may have been edited, truncated or written by another version of the library; a store
     * treats the exception as "no usable token" rather than guessing at a half-valid one.
     *
     * @param array<mixed> $data
     * @throws \InvalidArgumentException when the data is not a token of the current format.
     */
    public static function fromArray(
        #[\SensitiveParameter]
        array $data,
    ): self {
        if (($data['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('AccessToken: unsupported or missing format version.');
        }

        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
        $environment = $data['environment'] ?? null;
        $clientIdHash = $data['client_id_hash'] ?? null;

        if (!is_string($accessToken) || ($refreshToken !== null && !is_string($refreshToken))) {
            throw new \InvalidArgumentException('AccessToken: the tokens must be strings.');
        }

        if (!is_string($expiresAt) || !is_string($environment) || !is_string($clientIdHash)) {
            throw new \InvalidArgumentException('AccessToken: expires_at, environment and client_id_hash must be strings.');
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $expiresAt);

        if ($parsed === false) {
            throw new \InvalidArgumentException('AccessToken: expires_at is not an ISO 8601 date-time.');
        }

        return new self($accessToken, $parsed, $refreshToken, $environment, $clientIdHash);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted]',
            'refreshToken' => $this->refreshToken === null ? null : '[redacted]',
            'expiresAt' => $this->expiresAt,
            'environment' => $this->environment,
            'clientIdHash' => $this->clientIdHash,
        ];
    }
}
