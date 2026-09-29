<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;

/**
 * Gets a new session when the refresh token cannot: it expired after three months, was spent or
 * was pushed out by newer sessions, or there is no token at all.
 *
 * Without one, {@see TokenManager} throws {@see FurgonetkaApiReauthorizationRequiredException}
 * and a person has to connect the account again. A reauthorizer removes that person from the
 * loop, at the price of keeping whatever it needs to log in on the server. The only one shipped,
 * {@see PasswordReauthorizer}, needs the account password there, which is why it is opt-in.
 */
interface ReauthorizerInterface
{
    /**
     * Obtain a fresh token through `$oauth`. Called by the token manager while it holds the
     * store lock, so do not touch the store here; the manager saves the result.
     *
     * The returned token must come from `$oauth`, which stamps it with the environment and
     * application it belongs to. A token for another environment or application is refused.
     *
     * @throws FurgonetkaApiReauthorizationRequiredException when a login without a person is
     *         impossible (for example the account has two-factor authentication).
     */
    public function reauthorize(OAuthClient $oauth): AccessToken;
}
