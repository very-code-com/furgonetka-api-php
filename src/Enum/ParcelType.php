<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Kind of a parcel, sent per parcel in `parcels[].type` (`ParcelV2`).
 *
 * In version 2 of the shipment operations the type moved from the shipment to each parcel, so one
 * shipment can mix kinds. Which kinds a carrier accepts is not in the specification, and the
 * sandbox refuses most of them (`furniture`, `machines`, `other`, `vehicles`) for every carrier of a
 * fresh account with `notInArray`, so a value that is valid here can still be refused for the
 * carrier you picked.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum ParcelType: string
{
    /** Parcel. */
    case Package = 'package';

    /** Envelope. */
    case Dox = 'dox';

    /** Pallet. */
    case Pallet = 'pallet';

    /** Letter print (Postivo). */
    case Letterprint = 'letterprint';

    /** Furniture; needs `quantity`. */
    case Furniture = 'furniture';

    /** Vehicles; needs `brand` and `model`. */
    case Vehicles = 'vehicles';

    /** Machines; needs `quantity`. */
    case Machines = 'machines';

    /** Other goods; needs `quantity`. */
    case Other = 'other';
}
