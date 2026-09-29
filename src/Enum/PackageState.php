<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * State of a shipment in the panel (`Package.state`).
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum PackageState: string
{
    /** Created, not ordered yet. */
    case Waiting = 'waiting';

    /** Ordering is in progress. */
    case Queued = 'queued';

    /** Ordered. */
    case Ordered = 'ordered';

    /** Cancelled. */
    case Cancelled = 'cancelled';

    /** Only tracked, not sent through Furgonetka. */
    case Tracking = 'tracking';

    /** In storage at a pickup point. */
    case Storage = 'storage';

    /** A freight exchange offer. */
    case Auction = 'auction';
}
