<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Which list of shipments to read (`GET /packages?list_type=`).
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum ListType: string
{
    /** Sent shipments. */
    case Sent = 'sent';

    /** Ordered shipments. */
    case Ordered = 'ordered';

    /** Created and waiting. */
    case Waiting = 'waiting';

    /** All shipments. */
    case All = 'all';

    /** In storage. */
    case Storage = 'storage';

    /** Only tracked. */
    case Tracking = 'tracking';

    /** Freight exchange offers. */
    case Auction = 'auction';
}
