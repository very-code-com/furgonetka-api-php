<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Time;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Time\Clock;

final class ClockTest extends TestCase
{
    public function testWithoutASourceItIsTheSystemClock(): void
    {
        $before = time();
        $now = (new Clock())->now()->getTimestamp();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual(time(), $now);
    }

    public function testASourceDecidesWhatNowIs(): void
    {
        $instant = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        self::assertSame($instant, (new Clock(static fn (): \DateTimeImmutable => $instant))->now());
    }
}
