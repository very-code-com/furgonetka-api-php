<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RateLimitReader;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

final class RateLimitReaderTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function read(array $headers): ?\VeryCodeCom\FurgonetkaApi\Dto\RateLimitStatus
    {
        return (new RateLimitReader())->read(new TransportResponse(200, '{}', $headers));
    }

    public function testTheHeadersOfAnAnswerBelowTheLimit(): void
    {
        // Exactly what the sandbox sends on every answer while the limit is not exceeded.
        $status = $this->read(['x-ratelimit-limit' => '500', 'x-ratelimit-remaining' => '498', 'x-ratelimit-retry-after' => '0']);

        self::assertNotNull($status);
        self::assertSame(500, $status->limit);
        self::assertSame(498, $status->remaining);
        self::assertNull($status->retryAfterSeconds, 'A Retry-After of 0 means there is nothing to wait for.');
    }

    public function testTheCoolDownOfAnExhaustedWindow(): void
    {
        $status = $this->read(['x-ratelimit-limit' => '500', 'x-ratelimit-remaining' => '0', 'x-ratelimit-retry-after' => '1234']);

        self::assertNotNull($status);
        self::assertSame(0, $status->remaining);
        self::assertSame(1234, $status->retryAfterSeconds);
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $status = $this->read(['X-RateLimit-Limit' => '5000', 'X-RateLimit-Remaining' => '4999']);

        self::assertNotNull($status);
        self::assertSame(5000, $status->limit);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unusableHeaders(): iterable
    {
        yield 'no headers at all' => [[]];
        yield 'only the limit' => [['x-ratelimit-limit' => '500']];
        yield 'only the remainder' => [['x-ratelimit-remaining' => '10']];
        yield 'not numbers' => [['x-ratelimit-limit' => 'many', 'x-ratelimit-remaining' => 'some']];
        yield 'negative' => [['x-ratelimit-limit' => '500', 'x-ratelimit-remaining' => '-1']];
        yield 'blank' => [['x-ratelimit-limit' => ' ', 'x-ratelimit-remaining' => ' ']];
        yield 'decimals' => [['x-ratelimit-limit' => '500.5', 'x-ratelimit-remaining' => '10']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('unusableHeaders')]
    public function testHeadersThatAreNotAUsableQuotaGiveNull(array $headers): void
    {
        self::assertNull($this->read($headers));
    }
}
