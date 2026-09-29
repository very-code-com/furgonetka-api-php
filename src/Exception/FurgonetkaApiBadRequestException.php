<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 400: Furgonetka rejected the request as invalid.
 *
 * The body lists what was wrong in `errors`, each entry with the `path` of the offending field
 * (see {@see self::getFieldPaths()}). `validateRemotely()` returns the same verdict without
 * creating anything, so it is the cheap way to check a payload before sending it.
 */
class FurgonetkaApiBadRequestException extends FurgonetkaApiHttpException
{
}
