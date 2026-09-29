<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Carrier of a pickup point (`service` of a point) and the values of the points filter.
 *
 * Not the same list as {@see Carrier}: it includes Furgonetka's own points and leaves out
 * carriers that have none.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum PointService: string
{
    /** InPost. */
    case Inpost = 'inpost';

    /** RUCH. */
    case Ruch = 'ruch';

    /** Poczta Polska. */
    case Poczta = 'poczta';

    /** UPS Access Point. */
    case Ups = 'ups';

    /** DHL. */
    case Dhl = 'dhl';

    /** DPD Pickup. */
    case Dpd = 'dpd';

    /** Meest. */
    case Meest = 'meest';

    /** FedEx. */
    case Fedex = 'fedex';

    /** ORLEN Paczka. */
    case Orlen = 'orlen';

    /** A Furgonetka Punkt. */
    case FurgonetkaPunkt = 'furgonetkaPunkt';
}
