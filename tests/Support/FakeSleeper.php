<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Command\SleeperInterface;

/**
 * A sleeper that does not sleep: it records the waits and moves a fake clock forward by them, so
 * a test of "the command finishes after four reads" or "the command times out after a minute"
 * takes no time and can say exactly how long the client would have waited.
 *
 * {@see self::clock()} is the clock to give the client, so that the timeout is measured on the same
 * time the sleeps advance. {@see self::advance()} stands for a slow request.
 */
final class FakeSleeper implements SleeperInterface
{
    /** @var list<float> Every wait, in seconds, in order. */
    public array $sleeps = [];

    private float $now;

    public function __construct(float $start = 1_800_000_000.0)
    {
        $this->now = $start;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }

    /** Time passes without a sleep, for example a request that took long. */
    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    /** The clock to hand to the code under test. */
    public function clock(): \Closure
    {
        return function (): \DateTimeImmutable {
            $instant = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $this->now));

            return $instant !== false ? $instant : throw new \LogicException('The fake clock produced no date.');
        };
    }

    /** Total seconds waited. */
    public function total(): float
    {
        return array_sum($this->sleeps);
    }
}
