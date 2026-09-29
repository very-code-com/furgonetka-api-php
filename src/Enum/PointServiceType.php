<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Kind of a pickup point.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum PointServiceType: string
{
    /** A staffed point. */
    case ServicePoint = 'service_point';

    /** A parcel locker. */
    case ParcelMachine = 'parcel_machine';
}
