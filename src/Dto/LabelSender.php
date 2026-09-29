<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * Other data on the label instead of the real sender's (`sender`): Furgonetka's "Inne dane na
 * etykiecie", the counterpart of a neutral address.
 *
 * It changes what is printed and nothing else. The courier still collects at
 * {@see Pickup}, and **returns go to the address on the label**, not to the pickup address, so a
 * dropshipper who prints the manufacturer here gets the returns at the manufacturer's.
 *
 * Available for DPD, DHL, FedEx, UPS, InPost and AmbroExpress by the field's description, for a
 * different set by the description of the schema, and the API refuses it for other carriers (GLS
 * answers "Service is not supported by this carrier"). The sandbox also demands `email` and `phone`
 * for DPD, which the specification does not.
 *
 * Sent as `sender`; it is named after what it is, not after the wire, because the real sender is
 * the pickup.
 */
final class LabelSender
{
    /**
     * @param string      $street      Street, house and flat number.
     * @param string      $postcode    Postcode.
     * @param string      $city        City.
     * @param string|null $name        First and last name; `name` or `company` is required.
     * @param string|null $company     Company name.
     * @param string|null $countryCode ISO 3166-1 alpha-2.
     * @param string|null $county      Required for Ireland, the United States and Canada.
     * @param string|null $email       E-mail.
     * @param string|null $phone       Phone number.
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
        ]);
    }
}
