<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** What Furgonetka says about a carrier next to a price quote (`courier_details`). */
final class CourierDetails
{
    /**
     * @param int|null  $amountFreeInsurance    Amount the carrier insures for free, PLN.
     * @param int|null  $returnCodDays          Days until the COD is paid back.
     * @param bool|null $codExpress             Whether the carrier supports express COD.
     * @param int|null  $parcelLockerPickupDays Days to collect a parcel from a locker.
     * @param int|null  $servicePointPickupDays Days to collect a parcel from a service point.
     * @param int|null  $avgDeliveryTime        Average delivery time, in minutes.
     */
    public function __construct(
        public readonly ?int $amountFreeInsurance = null,
        public readonly ?int $returnCodDays = null,
        public readonly ?bool $codExpress = null,
        public readonly ?int $parcelLockerPickupDays = null,
        public readonly ?int $servicePointPickupDays = null,
        public readonly ?int $avgDeliveryTime = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::int($data, 'amount_free_insurance'),
            Value::int($data, 'return_cod_days'),
            Value::bool($data, 'cod_express'),
            Value::int($data, 'parcel_locker_pickup_days'),
            Value::int($data, 'service_point_pickup_days'),
            Value::int($data, 'avg_delivery_time'),
        );
    }
}
