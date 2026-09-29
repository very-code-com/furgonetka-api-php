<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * The receiver of the shipment (`receiver`).
 *
 * Street, postcode and city are optional in this class because a delivery to an InPost parcel
 * locker or an ORLEN Paczka point does not need them: the point stands for the address. For every
 * other delivery they are required, and the validator says so when it knows the carrier.
 *
 * `name` or `company` is required, except for InPost, where both are optional. The length rules are
 * those of {@see Pickup}.
 */
final class Receiver
{
    /**
     * @param string|null $name        First and last name.
     * @param string|null $company     Company name.
     * @param string|null $street      Street, house and flat number.
     * @param string|null $postcode    Postcode.
     * @param string|null $city        City.
     * @param string|null $countryCode ISO 3166-1 alpha-2.
     * @param string|null $county      Required for Ireland (county), the United States (state code) and Canada (province code).
     * @param string|null $email       E-mail.
     * @param string|null $phone       Phone number, with the country prefix and a plus for a foreign number.
     * @param string|null $point       Delivery point (UPS Access Point, InPost, Poczta, Meest, GLS, ORLEN Paczka, DPD Pickup).
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
        public readonly ?string $phone = null,
        public readonly ?string $point = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'name' => $this->name,
            'company' => $this->company,
            'street' => $this->street,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'county' => $this->county,
            'email' => $this->email,
            'phone' => $this->phone,
            'point' => $this->point,
        ]);
    }
}
