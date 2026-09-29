<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Whether an address book entry holds a person's or a company's invoice data (`invoice_data_type`).
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum InvoiceDataType: string
{
    /** A private person. */
    case Personal = 'personal';

    /** A company. */
    case Company = 'company';
}
