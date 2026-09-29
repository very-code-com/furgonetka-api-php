<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

/**
 * Where the request quota stands, as reported by the `X-RateLimit-*` headers that every
 * Furgonetka response carries.
 *
 * Furgonetka documents 5000 requests per hour for an OAuth application and 500 for unauthenticated
 * traffic, but an authenticated request on the sandbox reports a limit of 500, so treat the
 * numbers in the headers, not the documentation, as the truth. When the limit is exceeded the API
 * answers 429 and asks not to be called again before {@see self::$retryAfterSeconds}.
 */
final class RateLimitStatus
{
    /**
     * @param int      $limit             Requests allowed in the current window (`X-RateLimit-Limit`).
     * @param int      $remaining         Requests left in it (`X-RateLimit-Remaining`).
     * @param int|null $retryAfterSeconds Seconds until the window resets, only while the limit is
     *                                    exceeded. Furgonetka sends `0` on every answer below the
     *                                    limit, and that means "nothing to wait for", so it is null.
     */
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }

    /** True when no request is left in the window. */
    public function isExhausted(): bool
    {
        return $this->remaining <= 0;
    }

    /** Share of the window still available, from 0.0 to 1.0; 0.0 when the limit is unknown. */
    public function remainingFraction(): float
    {
        return $this->limit > 0 ? max(0.0, min(1.0, $this->remaining / $this->limit)) : 0.0;
    }

    /**
     * True when less than the given share of the window is left. The client logs a warning below
     * 10%, which is the default here.
     */
    public function isLow(float $threshold = 0.10): bool
    {
        return $this->limit > 0 && $this->remainingFraction() < $threshold;
    }
}
