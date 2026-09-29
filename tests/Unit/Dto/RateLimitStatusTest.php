<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\RateLimitStatus;

final class RateLimitStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, bool, float, bool}> limit, remaining, exhausted, fraction, low
     */
    public static function quotas(): iterable
    {
        yield 'untouched' => [500, 500, false, 1.0, false];
        yield 'half' => [500, 250, false, 0.5, false];
        yield 'exactly ten percent' => [500, 50, false, 0.1, false];
        yield 'just below ten percent' => [500, 49, false, 0.098, true];
        yield 'one left' => [500, 1, false, 0.002, true];
        yield 'none left' => [500, 0, true, 0.0, true];
        yield 'below zero is treated as none' => [500, -3, true, 0.0, true];
        yield 'more than the limit is capped' => [500, 900, false, 1.0, false];
        yield 'an unknown limit is never low' => [0, 0, true, 0.0, false];
    }

    #[DataProvider('quotas')]
    public function testTheQuotaIsReadable(int $limit, int $remaining, bool $exhausted, float $fraction, bool $low): void
    {
        $status = new RateLimitStatus($limit, $remaining);

        self::assertSame($exhausted, $status->isExhausted());
        self::assertEqualsWithDelta($fraction, $status->remainingFraction(), 0.0001);
        self::assertSame($low, $status->isLow());
    }

    public function testTheThresholdCanBeChanged(): void
    {
        $status = new RateLimitStatus(1000, 300);

        self::assertFalse($status->isLow());
        self::assertTrue($status->isLow(0.5));
        self::assertFalse($status->isLow(0.3), 'Exactly at the threshold is not below it.');
    }
}
