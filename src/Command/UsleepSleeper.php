<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Command;

/** The default sleeper: blocks the process with `usleep()`. */
final class UsleepSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
