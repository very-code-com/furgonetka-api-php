<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Http;

use VeryCodeCom\FurgonetkaApi\Dto\RateLimitStatus;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Reads the request quota from a response.
 *
 * The headers are `X-RateLimit-Limit`, `X-RateLimit-Remaining` and the non-standard
 * `X-RateLimit-Retry-After`. Retry-After is 0 while the limit is not exceeded, and
 * {@see TransportResponse::retryAfterSeconds()} already turns that 0 into "no wait".
 *
 * @internal
 */
final class RateLimitReader
{
    /**
     * The quota after this response, or null when the response carries no usable limit (an
     * answer from a proxy in front of the API, for instance).
     */
    public function read(TransportResponse $response): ?RateLimitStatus
    {
        $limit = self::integer($response->header('x-ratelimit-limit'));
        $remaining = self::integer($response->header('x-ratelimit-remaining'));

        if ($limit === null || $remaining === null) {
            return null;
        }

        return new RateLimitStatus($limit, $remaining, $response->retryAfterSeconds());
    }

    /** A non-negative whole number, or null for anything else. */
    private static function integer(?string $value): ?int
    {
        $value = trim($value ?? '');

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }
}
