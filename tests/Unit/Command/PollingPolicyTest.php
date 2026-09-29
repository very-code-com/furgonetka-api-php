<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Command\UsleepSleeper;

final class PollingPolicyTest extends TestCase
{
    public function testTheDefaultsAreASecondAndAMinute(): void
    {
        $policy = new PollingPolicy();

        self::assertSame(1.0, $policy->interval);
        self::assertSame(60.0, $policy->timeout);
        self::assertSame(1.0, $policy->delay(0));
        self::assertSame(1.0, $policy->delay(40), 'Without a backoff the wait stays constant.');
    }

    public function testABackoffGrowsTheWaitUpToTheCeiling(): void
    {
        $policy = new PollingPolicy(interval: 1.0, backoff: 2.0, maxInterval: 5.0);

        self::assertSame([1.0, 2.0, 4.0, 5.0, 5.0, 5.0], array_map($policy->delay(...), [0, 1, 2, 3, 4, 20]));
    }

    public function testAFractionalBackoff(): void
    {
        $policy = new PollingPolicy(interval: 0.5, backoff: 1.5, maxInterval: 10.0);

        self::assertEqualsWithDelta(0.5, $policy->delay(0), 0.0001);
        self::assertEqualsWithDelta(0.75, $policy->delay(1), 0.0001);
        self::assertEqualsWithDelta(1.125, $policy->delay(2), 0.0001);
    }

    public function testANegativeAttemptIsTheFirst(): void
    {
        self::assertSame(1.0, (new PollingPolicy(backoff: 3.0))->delay(-4));
    }

    /**
     * @return iterable<string, array{array<string, float>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'a zero interval' => [['interval' => 0.0], 'must be positive'];
        yield 'a negative timeout' => [['timeout' => -1.0], 'must be positive'];
        yield 'a zero ceiling' => [['maxInterval' => 0.0], 'must be positive'];
        yield 'a shrinking wait' => [['backoff' => 0.5], 'at least 1.0'];
    }

    /**
     * @param array<string, float> $arguments
     */
    #[DataProvider('invalid')]
    public function testAPolicyThatCannotWorkIsRefused(array $arguments, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PollingPolicy(...$arguments);
    }

    public function testTheRealSleeperWaitsAndAZeroWaitIsInstant(): void
    {
        $sleeper = new UsleepSleeper();

        $started = microtime(true);
        $sleeper->sleep(0.05);
        $sleeper->sleep(0.0);
        $sleeper->sleep(-1.0);

        self::assertGreaterThanOrEqual(0.045, microtime(true) - $started);
        self::assertLessThan(0.5, microtime(true) - $started);
    }
}
