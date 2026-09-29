<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

/**
 * A token that is managed outside the library: it is handed out as it is, never refreshed and
 * never checked for expiry.
 *
 * Use it when another system owns the OAuth session (a token vault, a value injected by a
 * platform) or for a quick script with a token pasted from the panel. The library does not touch
 * the token's lifetime, so an expired one simply makes Furgonetka answer 401.
 */
final class StaticTokenProvider implements TokenProviderInterface
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $token,
    ) {
        if (trim($token) === '') {
            throw new \InvalidArgumentException('StaticTokenProvider: the token must not be empty.');
        }
    }

    public function accessToken(): string
    {
        return $this->token;
    }

    /** Nothing to do: there is no other token to switch to. */
    public function invalidate(): void
    {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }
}
