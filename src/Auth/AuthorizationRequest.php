<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

/**
 * The first half of the authorization code flow: where to send the user and the `state` to
 * remember until they come back.
 *
 * Keep {@see self::$state} in the user's session (not in a cookie the user can edit, and not in
 * the URL of your own callback) and hand it back to `exchangeAuthorizationCode()` when Furgonetka
 * redirects to your callback. That comparison is what stops a forged callback from connecting an
 * attacker's account to the victim's session.
 */
final class AuthorizationRequest
{
    /**
     * @param string $url   Furgonetka's authorization page; redirect the user's browser to it.
     * @param string $state Random CSRF token, also embedded in the URL. Single use.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $state,
    ) {
    }
}
