<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * The Furgonetka product an account operation belongs to (`product` of a bill).
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum BillProduct: string
{
    /** Courier shipments. */
    case Courier = 'kurier';

    /** Business services. */
    case Business = 'biznes';

    /** The freight exchange. */
    case Exchange = 'gielda';
}
