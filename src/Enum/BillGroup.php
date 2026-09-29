<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * A group of account operations to list (`product` of `GET /account/balance`). Not the same as
 * {@see BillProduct}, which is the product a bill belongs to.
 */
enum BillGroup: string
{
    /** Courier services: shipments and what changes their price. */
    case CourierServices = 'courier_services';

    /** The carrier's commission, for instance of the freight exchange. */
    case HaulerCommission = 'hauler_commission';
}
