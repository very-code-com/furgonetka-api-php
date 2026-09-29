<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Command;

use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Command\SleeperInterface;
use VeryCodeCom\FurgonetkaApi\Dto\CommandResult;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Internal\Time\Clock;

/**
 * Waits for a command to finish: read its status, sleep, read again, until the status is final
 * or the policy's time is up.
 *
 * Ordering, pickups, cancellations and documents all run this cycle, so it is written once and the
 * public methods differ only in the payload they send and the result they read.
 *
 * The UUID is known before the first request, so a timeout, and a network failure on any read,
 * carries it. That is what makes it safe to come back later with `fetch*Command($uuid)` instead of
 * ordering a second time.
 *
 * @internal
 */
final class CommandRunner
{
    private readonly Clock $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock Source of "now" for the timeout; tests only. */
    public function __construct(private readonly SleeperInterface $sleeper, ?\Closure $clock = null)
    {
        $this->clock = new Clock($clock);
    }

    /**
     * Read the command until it is finished.
     *
     * A status that is not one of the six known ones counts as not finished: a status added after
     * this release must not be mistaken for a result. It ends in a timeout that shows it, see
     * {@see FurgonetkaApiCommandTimeoutException::lastStatus()}.
     *
     * @template TResult of CommandResult
     * @param \Closure(): TResult $read One read of the command's status.
     * @return TResult
     *
     * @throws FurgonetkaApiCommandTimeoutException when the command is not finished within the policy's timeout.
     * @throws FurgonetkaApiTransportException       when a read fails on the network; carries the command's UUID.
     * @throws FurgonetkaApiException                any other error the read throws (an HTTP error status, an answer that cannot be parsed), unchanged.
     */
    public function wait(string $uuid, \Closure $read, PollingPolicy $policy): CommandResult
    {
        $started = $this->seconds();
        $attempt = 0;

        while (true) {
            try {
                $result = $read();
            } catch (FurgonetkaApiTransportException $e) {
                throw $e->withCommandUuid($uuid);
            }

            if ($result->isFinished()) {
                return $result;
            }

            $remaining = $policy->timeout - ($this->seconds() - $started);

            if ($remaining <= 0) {
                throw new FurgonetkaApiCommandTimeoutException(
                    sprintf(
                        'Furgonetka command %s did not finish within %s seconds (last status: %s). It is not lost: read it later with the matching fetch method instead of submitting it again.',
                        $uuid,
                        rtrim(rtrim(sprintf('%.1f', $policy->timeout), '0'), '.'),
                        $result->statusValue ?? 'unknown',
                    ),
                    $uuid,
                    $result->statusValue,
                );
            }

            // The last wait is cut short so that the command is read once more at the deadline.
            $this->sleeper->sleep(min($policy->delay($attempt), $remaining));
            $attempt++;
        }
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
