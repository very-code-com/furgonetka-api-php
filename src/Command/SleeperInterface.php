<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Command;

/**
 * How the client waits between two reads of a command.
 *
 * Injected so that a test of "the command finishes after four polls" or "the command times out"
 * takes no time: a fake sleeper just advances a fake clock.
 */
interface SleeperInterface
{
    /** Wait for the given number of seconds (fractions allowed). */
    public function sleep(float $seconds): void;
}
