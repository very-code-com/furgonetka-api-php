<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Command;

/**
 * Where an asynchronous command stands (`status` of an order, pickup, cancel or documents command).
 *
 * Ordering, pickups, cancellations and documents are commands: the client sends
 * `PUT /<x>-command/{uuid}` and reads `GET` under the same UUID until the status is final.
 * Furgonetka adds values without notice, so read it with `tryFrom()`; a status that is not a member
 * is not final as far as this library knows, and the raw string stays on the result.
 *
 * `cancelled` is not in the documentation: the sandbox answered it for a command that was aborted while
 * it was still queued (`abortCommand()`).
 */
enum CommandStatus: string
{
    /** Accepted, not started. */
    case Queueing = 'queueing';

    /** Being carried out. */
    case Running = 'running';

    /** Done, every shipment succeeded. */
    case Successful = 'successful';

    /** Done, some shipments succeeded and some did not; the result says which. */
    case PartialSuccess = 'partial_success';

    /** Done, nothing succeeded; the result says why. */
    case Error = 'error';

    /** Aborted before it started (`abortCommand()`); nothing was done. Not in the documentation. */
    case Cancelled = 'cancelled';

    /** True for the four statuses that no longer change. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queueing, self::Running => false,
            self::Successful, self::PartialSuccess, self::Error, self::Cancelled => true,
        };
    }
}
