<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown on HTTP 429: the request limit for the current window is used up.
 *
 * Furgonetka allows 5000 requests per hour for an OAuth application and 500 for unauthenticated
 * traffic (per IP), and states that a throttled request must not be repeated before the time in
 * `X-RateLimit-Retry-After` has passed. {@see self::retryAfterSeconds()} carries that value.
 */
class FurgonetkaApiRateLimitException extends FurgonetkaApiHttpException
{
    private ?int $retryAfter = null;

    /**
     * Set the cool-down read from the response headers (fluent).
     *
     * @return $this
     */
    public function withRetryAfter(?int $seconds): static
    {
        $this->retryAfter = $seconds !== null && $seconds > 0 ? $seconds : null;
        return $this;
    }

    /** Seconds until the window resets, or null when the response did not say. */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }
}
