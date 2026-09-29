<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * State of a shipment in tracking (`Tracking.state`), also the values of the tracking filter and of the webhook.
 *
 * Furgonetka writes these with hyphens (`collect-problem`), unlike most of its other identifiers.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum TrackingState: string
{
    /** Not ordered yet. */
    case Waiting = 'waiting';

    /** Ordered, not collected yet. */
    case Ordered = 'ordered';

    /** The courier could not collect the shipment. */
    case CollectProblem = 'collect-problem';

    /** Collected by the courier. */
    case Collected = 'collected';

    /** In transit. */
    case Transit = 'transit';

    /** Out for delivery. */
    case Delivery = 'delivery';

    /** Delivery failed. */
    case DeliveryProblem = 'delivery-problem';

    /** Delivered. */
    case Delivered = 'delivered';

    /** An unexpected situation, see the tracking events. */
    case UnexpectedSituation = 'unexpected-situation';

    /** Cancelled. */
    case Canceled = 'canceled';

    /** Returned to the sender. */
    case Returned = 'returned';

    /** Ready for transport. */
    case ReadyForTransport = 'ready-for-transport';

    /** Waiting for the receiver, for instance in a pickup point. */
    case WaitingForReceiver = 'waiting-for-receiver';
}
