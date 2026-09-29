<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * What kind of operation a bill is (`type`). The values are Furgonetka's own, some of them Polish
 * words, and are written here exactly as sent.
 *
 * `gielda_commission` is not in the specification; the sandbox sent it for the commission of the
 * freight exchange.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum BillType: string
{
    /** A shipment (`wysyłka`); the bill's `transaction` is the shipment's id. */
    case Shipment = 'wysyłka';

    /** A top-up through DotPay. */
    case DotPay = 'DotPay';

    /** A refund, for instance for a cancelled shipment (`zwrot`). */
    case Refund = 'zwrot';

    /** A correction (`korekta`). */
    case Correction = 'korekta';

    /** A correction because the parcel weighed more than declared (`korekta_waga`). */
    case WeightCorrection = 'korekta_waga';

    /** A payout (`wyplata`). */
    case Payout = 'wyplata';

    /** A transfer between accounts. */
    case Transfer = 'transfer';

    /** A debit note (`nota`). */
    case Note = 'nota';

    /** A parcel locker (`szafa`). */
    case Locker = 'szafa';

    /** A Furgonetka group. */
    case FurgonetkaGroup = 'grupa_furgonetka';

    /** A top-up through mPay. */
    case Mpay = 'mpay';

    /** A payment at a Furgonetka Point. */
    case FurgonetkaPointPayment = 'furgonetka_point_payment';

    /** A charge at a Furgonetka Point. */
    case FurgonetkaPointCharge = 'furgonetka_point_charge';

    /** A top-up through Przelewy24. */
    case P24 = 'p24';

    /** The commission of the freight exchange; not in the specification. */
    case ExchangeCommission = 'gielda_commission';
}
