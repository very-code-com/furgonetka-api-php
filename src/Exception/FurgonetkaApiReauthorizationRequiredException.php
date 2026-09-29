<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown when the library has no way left to obtain a valid access token on its own.
 *
 * That happens when the stored refresh token was rejected (`invalid_grant`: it expired after
 * three months, was used already, or was invalidated by newer sessions) or when there is no
 * token in the store at all and no reauthorizer was configured.
 *
 * The connection has to be made again: the password grant for your own shop's account, the
 * authorization code flow for a customer's account. Re-login without a person present is
 * possible only when you opt in with a reauthorizer, which needs the account password in your
 * server's environment; see the documentation before deciding to do that.
 */
class FurgonetkaApiReauthorizationRequiredException extends FurgonetkaApiOAuthException
{
}
