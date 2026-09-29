<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Command;

/**
 * How long and how often to wait for a command to finish.
 *
 * The defaults are what Furgonetka's own example does (read the status every second) with a
 * ceiling of 60 seconds. Ordering is usually done in a few seconds, but a carrier that is slow to
 * answer can make it longer; when the ceiling is reached the client throws
 * {@see \VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException}, which carries
 * the command's UUID so that the result can be read later instead of ordering a second time.
 *
 * Every read counts against the request limit (500 an hour on most sandbox endpoints), so a
 * batch job that waits for many commands should use a backoff.
 */
final class PollingPolicy
{
    /**
     * @param float $interval    Seconds before the first re-read.
     * @param float $backoff     Factor applied to the wait after each re-read; 1.0 keeps it constant.
     * @param float $maxInterval Longest single wait, however far the backoff has grown.
     * @param float $timeout     Seconds after which the client gives up waiting.
     *
     * @throws \InvalidArgumentException when a value cannot work.
     */
    public function __construct(
        public readonly float $interval = 1.0,
        public readonly float $backoff = 1.0,
        public readonly float $maxInterval = 10.0,
        public readonly float $timeout = 60.0,
    ) {
        if ($interval <= 0 || $maxInterval <= 0 || $timeout <= 0) {
            throw new \InvalidArgumentException('PollingPolicy: interval, maxInterval and timeout must be positive.');
        }

        if ($backoff < 1.0) {
            throw new \InvalidArgumentException('PollingPolicy: backoff must be at least 1.0 (a wait never shrinks).');
        }
    }

    /**
     * The wait before re-read number `$attempt` (0 for the first re-read), capped by
     * {@see self::$maxInterval}.
     */
    public function delay(int $attempt): float
    {
        return min($this->maxInterval, $this->interval * ($this->backoff ** max(0, $attempt)));
    }
}
