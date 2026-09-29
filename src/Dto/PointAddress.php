<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The address of a pickup point, and the address a search can be centred on.
 *
 * What comes back is the carrier's own data, not normalised: a postcode may come without the
 * hyphen ("00026"), a street in capitals with a pavilion number, a province as an empty string
 * (read as null).
 */
final class PointAddress
{
    /**
     * @param string|null $postcode    Postcode.
     * @param string|null $street      Street, house and flat number.
     * @param string|null $city        City.
     * @param string|null $countryCode ISO 3166-1 alpha-2.
     * @param string|null $province    Province or region.
     * @param string|null $houseNumber Building number, when the carrier gives it apart from the street.
     */
    public function __construct(
        public readonly ?string $postcode = null,
        public readonly ?string $street = null,
        public readonly ?string $city = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $province = null,
        public readonly ?string $houseNumber = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            postcode: Value::string($data, 'postcode'),
            street: Value::string($data, 'street'),
            city: Value::string($data, 'city'),
            countryCode: Value::string($data, 'country_code'),
            province: Value::string($data, 'province'),
            houseNumber: Value::string($data, 'house_number'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'postcode' => $this->postcode,
            'street' => $this->street,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'province' => $this->province,
            'house_number' => $this->houseNumber,
        ]);
    }
}
