<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * File format of a shipping label, chosen when the shipments are ordered (`label.file_format`) or taken from the account settings.
 * It cannot be changed afterwards. A PDF is served as `application/pdf`, ZPL and EPL as text.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum LabelFileFormat: string
{
    /** PDF, printed on A4 or A6, see {@see LabelPageFormat}. */
    case Pdf = 'pdf';

    /** ZPL, for Zebra thermal printers. */
    case Zpl = 'zpl';

    /** EPL, for older thermal printers. */
    case Epl = 'epl';
}
