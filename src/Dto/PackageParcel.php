<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\ParcelState;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One parcel of a shipment as Furgonetka reads it back (`parcels[]`, version 2), with its own
 * type, state and carrier number.
 *
 * `packageNo` is the carrier's waybill number and is null until the shipment is ordered; it is
 * what a recipient tracks. `state` and `type` are enums read with `tryFrom()`: a value added after
 * this release reads as null here and stays in `stateValue` and `typeValue`.
 */
final class PackageParcel
{
    /**
     * @param string|null                $packageNo        Carrier waybill number of this parcel.
     * @param string|null                $description      Contents.
     * @param string|null                $stateDescription State in words, in the language of the request.
     * @param ParcelState|null           $state            State; null when it is not a member.
     * @param string|null                $stateValue       State as sent.
     * @param int|null                   $width            Width, cm.
     * @param int|null                   $depth            Length, cm.
     * @param int|null                   $height           Height, cm.
     * @param float|null                 $weight           Weight, kg.
     * @param float|null                 $dimensionalWeight Volumetric weight, kg.
     * @param float|null                 $value            Declared value, PLN.
     * @param ParcelType|null            $type             Kind of parcel; null when it is not a member.
     * @param string|null                $typeValue        Kind of parcel as sent.
     * @param string|null                $trackingUrl      Public tracking page.
     * @param string|null                $service          Carrier identifier.
     * @param int|null                   $quantity         Number of items (furniture, machines, other).
     * @param string|null                $brand            Car make (vehicles).
     * @param string|null                $model            Car model (vehicles).
     * @param \DateTimeImmutable|null    $datetimeStatus   When the state last changed.
     * @param array<string, mixed>       $raw              The whole object as Furgonetka sent it.
     */
    public function __construct(
        public readonly ?string $packageNo = null,
        public readonly ?string $description = null,
        public readonly ?string $stateDescription = null,
        public readonly ?ParcelState $state = null,
        public readonly ?string $stateValue = null,
        public readonly ?int $width = null,
        public readonly ?int $depth = null,
        public readonly ?int $height = null,
        public readonly ?float $weight = null,
        public readonly ?float $dimensionalWeight = null,
        public readonly ?float $value = null,
        public readonly ?ParcelType $type = null,
        public readonly ?string $typeValue = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $service = null,
        public readonly ?int $quantity = null,
        public readonly ?string $brand = null,
        public readonly ?string $model = null,
        public readonly ?\DateTimeImmutable $datetimeStatus = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            packageNo: Value::string($data, 'package_no'),
            description: Value::string($data, 'description'),
            stateDescription: Value::string($data, 'state_description'),
            state: Value::enum($data, 'state', ParcelState::class),
            stateValue: Value::string($data, 'state'),
            width: Value::int($data, 'width'),
            depth: Value::int($data, 'depth'),
            height: Value::int($data, 'height'),
            weight: Value::float($data, 'weight'),
            dimensionalWeight: Value::float($data, 'dimensional_weight'),
            value: Value::float($data, 'value'),
            type: Value::enum($data, 'type', ParcelType::class),
            typeValue: Value::string($data, 'type'),
            trackingUrl: Value::string($data, 'tracking_url'),
            service: Value::string($data, 'service'),
            quantity: Value::int($data, 'quantity'),
            brand: Value::string($data, 'brand'),
            model: Value::string($data, 'model'),
            datetimeStatus: Value::dateTime($data, 'datetime_status'),
            raw: $data,
        );
    }

    /**
     * The whole object as Furgonetka sent it, for a field added since this release.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
