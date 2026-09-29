<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

use VeryCodeCom\FurgonetkaApi\Dto\ApiError;

/**
 * Thrown when Furgonetka answers an API request with an HTTP error status.
 *
 * Furgonetka reports errors in two shapes on the API host, and both end up in {@see self::$errors}:
 *
 *   {"errors": [{"path": "/receiver/postcode", "message": "...", "code": "..."}]}    400, 404, 410, 500
 *   {"code": "access_denied", "message": "...", "details": "..."}                     401
 *
 * The client throws the narrowest subclass it can (bad request, auth, not found, conflict, gone,
 * rate limit, server error); this class is the fallback for any other status.
 *
 * Carrier rejections are not HTTP errors at all. They arrive inside the result of a command or in
 * a price quote, so a successful `orderPackages()` call can still report failed shipments.
 */
class FurgonetkaApiHttpException extends FurgonetkaApiException
{
    /**
     * @param int            $statusCode HTTP status code Furgonetka returned.
     * @param list<ApiError> $errors     Every error reported in the body, in order.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * The paths Furgonetka complained about, without repeats.
     *
     * @return list<string>
     */
    public function getFieldPaths(): array
    {
        $paths = [];

        foreach ($this->errors as $error) {
            if ($error->path !== null) {
                $paths[$error->path] = true;
            }
        }

        return array_map('strval', array_keys($paths));
    }

    /** True when any reported error carries this machine-readable code. */
    public function hasErrorCode(string $code): bool
    {
        foreach ($this->errors as $error) {
            if ($error->code === $code) {
                return true;
            }
        }

        return false;
    }
}
