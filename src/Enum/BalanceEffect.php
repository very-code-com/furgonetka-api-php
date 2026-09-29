<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * The sign of an account operation, to list only the money that came in or only the money that went out
 * (`balance_effect` of `GET /account/balance`).
 */
enum BalanceEffect: string
{
    /** Operations that raised the balance: top-ups, refunds, corrections in your favour. */
    case Plus = 'plus';

    /** Operations that lowered the balance: shipments, commissions, corrections against you. */
    case Minus = 'minus';
}
