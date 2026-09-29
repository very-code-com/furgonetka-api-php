<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

/**
 * The OAuth application keys issued by Furgonetka (Client ID, Client Secret) and the redirect
 * URI registered with them.
 *
 * These are configuration, not state: they identify your application and never change while it
 * runs. What does change, and what must be stored, are the tokens obtained with them.
 *
 * The secret never appears in a `var_dump()`, a `print_r()` or a stack trace: the constructor
 * argument is marked sensitive and {@see self::__debugInfo()} masks it.
 */
final class OAuthCredentials
{
    /**
     * @param string      $clientId     Client ID of the OAuth application (separate for sandbox and production).
     * @param string      $clientSecret Client Secret of that application.
     * @param string|null $redirectUri  Only needed for the authorization_code grant. It must be
     *                                  identical to the address registered with the application,
     *                                  Furgonetka compares it literally.
     */
    public function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter]
        public readonly string $clientSecret,
        public readonly ?string $redirectUri = null,
    ) {
        if (trim($clientId) === '') {
            throw new \InvalidArgumentException('OAuthCredentials: clientId must not be empty.');
        }

        // The Basic scheme joins id and secret with a colon, so an id containing one could not
        // be told apart from the secret on the server side.
        if (str_contains($clientId, ':')) {
            throw new \InvalidArgumentException('OAuthCredentials: clientId must not contain a colon.');
        }

        if (trim($clientSecret) === '') {
            throw new \InvalidArgumentException('OAuthCredentials: clientSecret must not be empty.');
        }

        if ($redirectUri !== null && trim($redirectUri) === '') {
            throw new \InvalidArgumentException('OAuthCredentials: redirectUri must not be an empty string, use null.');
        }
    }

    /**
     * The `Authorization` header value for the token endpoint: `Basic base64(client_id:client_secret)`,
     * exactly as Furgonetka's OAuth documentation describes it.
     */
    public function basicAuthorization(): string
    {
        return 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
    }

    /**
     * SHA-256 of the Client ID, hex encoded. A stored token carries it so that it can be told
     * apart from one issued to a different application: the token is only ever sent for the
     * application that owns it. The secret is deliberately not part of it, because rotating a
     * secret does not invalidate the tokens issued before.
     */
    public function clientIdHash(): string
    {
        return hash('sha256', $this->clientId);
    }

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => '[redacted]',
            'redirectUri' => $this->redirectUri,
        ];
    }
}
