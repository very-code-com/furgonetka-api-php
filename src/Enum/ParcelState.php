<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * State of one parcel in a shipment list (`PackagesListElementParcel.state`).
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum ParcelState: string
{
    /** Waiting to be sent. */
    case Waiting = 'waiting';

    /** Sent. */
    case Sent = 'sent';

    /** In transit. */
    case Transit = 'transit';

    /** Delivered. */
    case Delivered = 'delivered';

    /** Cancelled. */
    case Canceled = 'canceled';

    /** Collected by the courier. */
    case Collected = 'collected';

    /** Returned to the sender. */
    case Returned = 'returned';
}
