<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 401 and 403: Furgonetka does not accept the access token, or the account is
 * not allowed to do what was asked.
 *
 * The API host answers 401 in its own flat shape (`{"code": "access_denied", "message": "...",
 * "details": "..."}`), and `details` says which case it is. Seen on the sandbox:
 *  - `Missing "Authorization" header`: no token was sent at all.
 *  - `Error while decoding from JSON`: the token is not a valid token, for instance a
 *    sandbox token sent to production or the other way round.
 *
 * The client retries once with a freshly refreshed token before it throws this for a token it
 * manages itself, so seeing it means the refresh did not help.
 */
class FurgonetkaApiAuthException extends FurgonetkaApiHttpException
{
}
