<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Time;

/**
 * The current time, replaceable in tests.
 *
 * Token lifetimes are the one place where the library depends on the clock, and a test of "the
 * token expires in five minutes" must not have to sleep. Production code passes no source and
 * gets the system time; a test passes a closure that returns the instant it wants.
 *
 * psr/clock would be the standard interface for this, but it would add a dependency for one
 * method, and the library keeps to `ext-curl`, `ext-json`, `ext-mbstring` and `psr/log`.
 *
 * @internal
 */
final class Clock
{
    /** @param (\Closure(): \DateTimeImmutable)|null $source Returns "now"; null means the system clock. */
    public function __construct(private readonly ?\Closure $source = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->source !== null ? ($this->source)() : new \DateTimeImmutable();
    }
}
