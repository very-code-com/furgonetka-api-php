<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Whose contract with the carrier a service runs on (`owner` of a service in `GET /account/services`).
 *
 * The owner changes what is allowed: UPS on a Furgonetka contract takes one parcel per shipment,
 * on the customer's own contract up to fifteen.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum AgreementOwner: string
{
    /** Furgonetka's own contract with the carrier (the broker contract). */
    case Furgonetka = 'furgonetka';

    /** The customer's own contract with the carrier. */
    case Client = 'client';
}
