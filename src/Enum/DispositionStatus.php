<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * State of a return or readdressing order attached to a shipment (`return_disposition.status` and
 * `readdressing_disposition.status`): waiting for payment, or paid.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the DTO keeps the raw string next to it.
 */
enum DispositionStatus: string
{
    /** Created, waiting for payment. */
    case New = 'new';

    /** Paid. */
    case Paid = 'paid';
}
