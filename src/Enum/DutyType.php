<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Purpose of a shipment that goes through customs (`duty.type`).
 *
 * `sale` makes the sender's EORI number mandatory. The sandbox additionally asks for a VAT number
 * and an invoice number on a sale to Great Britain, which the specification does not say.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum DutyType: string
{
    /** Sale. */
    case Sale = 'sale';

    /** Samples, gifts and goods that are not for sale. */
    case Gift = 'gift';

    /** Documents in an envelope. */
    case DocsInDox = 'docs_in_dox';
}
