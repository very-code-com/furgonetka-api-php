<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Page size of a PDF label. Unlike the file format it can be chosen on every download.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum LabelPageFormat: string
{
    /** A4 sheet. */
    case A4 = 'a4';

    /** A6 sheet, the size of a thermal label. */
    case A6 = 'a6';
}
