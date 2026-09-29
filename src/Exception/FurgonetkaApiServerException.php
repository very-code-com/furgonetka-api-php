<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 5xx: the request failed inside Furgonetka rather than because of its contents.
 *
 * The specification documents a single 500, "Error While Generating Label", on the label
 * endpoint. 502, 503 and 504 are gateway-level failures and transient by nature; see
 * {@see self::isRetryable()}.
 */
class FurgonetkaApiServerException extends FurgonetkaApiHttpException
{
    /**
     * True for 502, 503 and 504. A plain 500 is reported as not retryable: repeating a request
     * that made the server fail tends to make it fail again.
     */
    public function isRetryable(): bool
    {
        return in_array($this->statusCode, [502, 503, 504], true);
    }
}
