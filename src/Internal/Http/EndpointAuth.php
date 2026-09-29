<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Http;

/**
 * What an endpoint expects in the `Authorization` header.
 *
 * Only two kinds matter for the endpoints this library calls: the user's bearer token, and
 * nothing at all. Endpoints that take an application token (`x-application-token`) belong to
 * partner flows that are out of scope.
 *
 * @internal
 */
enum EndpointAuth
{
    /** Public: no token is sent, even when the client has one. */
    case None;

    /** The OAuth access token of the user, sent as `Authorization: Bearer <token>`. */
    case User;
}
