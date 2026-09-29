<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * Where the courier collects the shipment, or the sender's data when the parcel is handed in at a
 * point or a branch (`pickup`).
 *
 * This is the real sender in Furgonetka's sense: the courier drives to this address and returns go
 * back to it, unless a {@see LabelSender} prints other data on the label.
 *
 * `name` or `company` is required: a full name is at least two letters for the first and the last
 * name, six to ninety characters in total; a company two to eighty. Carriers tighten that (DHL 60,
 * UPS 30, Poczta 60/30, FedEx company 150, ORLEN 30, AmbroExpress 60), and
 * {@see \VeryCodeCom\FurgonetkaApi\Internal\Validator\ShipmentValidator} checks it when it knows the carrier.
 */
final class Pickup
{
    /**
     * @param string      $street      Street, house and flat number.
     * @param string      $postcode    Postcode.
     * @param string      $city        City.
     * @param string|null $name        First and last name.
     * @param string|null $company     Company name.
     * @param string|null $countryCode ISO 3166-1 alpha-2.
     * @param string|null $county      Required for Ireland (county), the United States (state code) and Canada (province code).
     * @param string|null $email       E-mail.
     * @param string|null $phone       Phone number, with the country prefix and a plus for a foreign number.
     * @param string|null $point       Handover point (UPS Access Point, InPost, Poczta, Meest, GLS, ORLEN Paczka, DPD Pickup);
     *                                 `any_apm` (any parcel locker) and `any_parcel_shop` (any staffed point) for InPost, DPD, DHL, FedEx and UPS.
     */
    public function __construct(
        public readonly string $street,
        public readonly string $postcode,
        public readonly string $city,
        public readonly ?string $name = null,
        public readonly ?string $company = null,
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
