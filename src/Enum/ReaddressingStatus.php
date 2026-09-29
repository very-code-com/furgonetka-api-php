<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * State of a readdressing order (`details.status`): waiting for payment, paid, or cancelled.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum ReaddressingStatus: string
{
    /** Created, not paid yet. */
    case New = 'new';

    /** Paid. */
    case Paid = 'paid';

    /** Cancelled, see `cancelReaddressing()`. */
    case Cancelled = 'cancelled';
}
