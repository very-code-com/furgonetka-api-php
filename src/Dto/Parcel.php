<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * One parcel of a shipment (`parcels[]`, version 2).
 *
 * The type belongs to the parcel, not to the shipment (that was version 1), so one shipment can
 * mix kinds. Dimensions are in centimetres, the weight in kilograms and the value in PLN.
 *
 * `value` is what the shipment is insured for and, with cash on delivery, the floor under the COD
 * amount: DHL, FedEx and Poczta Polska reject a COD above the sum of the values.
 *
 * Every measure is optional here because what a carrier needs depends on the type (an envelope
 * has no meaningful height); the API is the authority and answers with the field paths.
 */
final class Parcel
{
    /**
     * @param ParcelType  $type        Kind of parcel.
     * @param int|null    $width       Width, cm.
     * @param int|null    $depth       Length, cm.
     * @param int|null    $height      Height, cm.
     * @param float|null  $weight      Weight, kg.
     * @param float|null  $value       Declared value, PLN.
     * @param string|null $description Contents, 2 to 100 characters (DHL 2 to 30, UPS 2 to 50). Documented as required
     *                                 for DHL, FedEx and Poczta Polska, though the sandbox validates without it.
     * @param int|null    $quantity    Number of items; needed for the types furniture, machines and other.
     * @param string|null $brand       Car make; needed for the type vehicles.
     * @param string|null $model       Car model; needed for the type vehicles.
     */
    public function __construct(
        public readonly ParcelType $type,
        public readonly ?int $width = null,
        public readonly ?int $depth = null,
        public readonly ?int $height = null,
        public readonly ?float $weight = null,
        public readonly ?float $value = null,
        public readonly ?string $description = null,
        public readonly ?int $quantity = null,
        public readonly ?string $brand = null,
        public readonly ?string $model = null,
    ) {
    }

    /** An ordinary parcel with its dimensions and weight. */
    public static function package(
        int $width,
        int $depth,
        int $height,
        float $weight,
        ?float $value = null,
        ?string $description = null,
    ): self {
        return new self(ParcelType::Package, $width, $depth, $height, $weight, $value, $description);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'type' => $this->type->value,
            'width' => $this->width,
            'depth' => $this->depth,
            'height' => $this->height,
            'weight' => $this->weight,
            'value' => $this->value,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'brand' => $this->brand,
            'model' => $this->model,
        ]);
    }
}
