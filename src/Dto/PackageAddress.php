<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One of the three addresses of a shipment as Furgonetka reads it back: the pickup (`pickup`), the
 * receiver (`receiver`) or the data printed on the label (`sender`).
 *
 * Furgonetka normalises what you sent (it turned "Krakow" into "Kraków"), so compare with care.
 * Which fields exist depends on the role: `uuid`, `buildingNumber` and `flatNumber` only on a
 * receiver, `onlyPickupProvided` only on a label sender. For a GLS pickup or sender, a null address
 * means the data comes from the carrier contract.
 *
 * The point details (`point_data`) are left in the shipment's `raw()`.
 */
final class PackageAddress
{
    /**
     * @param string|null      $name               First and last name.
     * @param string|null      $company            Company name.
     * @param string|null      $street             Street, house and flat number.
     * @param string|null      $postcode           Postcode.
     * @param string|null      $city               City.
     * @param string|null      $countryCode        ISO 3166-1 alpha-2.
     * @param string|null      $county             County, state or province (Ireland, the United States, Canada).
     * @param string|null      $email              E-mail.
     * @param PhoneNumber|null $phone              The phone number, split into prefix and number.
     * @param string|null      $point              The handover or delivery point.
     * @param string|null      $pointLabel         The label Furgonetka generated for that point.
     * @param string|null      $uuid               Receiver only: the public UUID of the "send to me" feature.
     * @param string|null      $buildingNumber     Receiver only: building number (Meest).
     * @param string|null      $flatNumber         Receiver only: flat number (Meest).
     * @param bool|null        $onlyPickupProvided Label sender only: true when the label carries the same data as the pickup.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $company = null,
        public readonly ?string $street = null,
        public readonly ?string $postcode = null,
        public readonly ?string $city = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $county = null,
        public readonly ?string $email = null,
        public readonly ?PhoneNumber $phone = null,
        public readonly ?string $point = null,
        public readonly ?string $pointLabel = null,
        public readonly ?string $uuid = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $flatNumber = null,
        public readonly ?bool $onlyPickupProvided = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $phone = Value::object($data, 'phone_number_data');

        return new self(
            name: Value::string($data, 'name'),
            company: Value::string($data, 'company'),
            street: Value::string($data, 'street'),
            postcode: Value::string($data, 'postcode'),
            city: Value::string($data, 'city'),
            countryCode: Value::string($data, 'country_code'),
            county: Value::string($data, 'county'),
            email: Value::string($data, 'email'),
            phone: $phone !== null ? PhoneNumber::fromArray($phone) : null,
            point: Value::string($data, 'point'),
            pointLabel: Value::string($data, 'point_label'),
            uuid: Value::string($data, 'uuid'),
            buildingNumber: Value::string($data, 'building_number'),
            flatNumber: Value::string($data, 'flat_number'),
            onlyPickupProvided: Value::bool($data, 'only_pickup_provided'),
        );
    }
}
