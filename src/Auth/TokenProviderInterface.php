<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;

/**
 * Where the client gets the bearer token for its next request.
 *
 * {@see TokenManager} is the implementation that keeps a token alive on its own;
 * {@see StaticTokenProvider} hands out a token that is managed somewhere else. Implement this to
 * plug in a token source of your own, for instance a shared secret manager.
 */
interface TokenProviderInterface
{
    /**
     * The access token to send with the next request. An implementation that manages tokens
     * refreshes here when the current one is about to expire.
     *
     * @throws FurgonetkaApiException when no valid token can be produced, most notably
     *         {@see \VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException}.
     */
    public function accessToken(): string;

    /**
     * Report that Furgonetka refused the token most recently handed out (an HTTP 401), so the next
     * {@see self::accessToken()} call must not return it again. A provider that cannot get a
     * different token does nothing here.
     *
     * The client calls this once per request and then retries once; a second refusal is an
     * error for the caller, not a reason to keep refreshing.
     */
    public function invalidate(): void;
}
