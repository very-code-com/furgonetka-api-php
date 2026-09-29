<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * The new receiver of a shipment that is being readdressed.
 *
 * A readdressing sends an ordered shipment to another address in Poland or the same country as
 * before; the specification gives no country field. What the documentation calls required and the
 * sandbox enforced (`isEmpty`, seen even on a shipment it then refused as "not possible") is
 * checked here: a name or a company, and the street, postcode, city and phone.
 */
final class ReaddressingReceiver
{
    /**
     * @param string      $postcode Postcode.
     * @param string      $city     City.
     * @param string      $street   Street, house and flat number.
     * @param string      $phone    Phone number.
     * @param string|null $name     First and last name; required when there is no company.
     * @param string|null $company  Company name; required when there is no name.
     * @param string|null $county   County; for Ireland, "CARLOW" in the specification's example.
     * @param string|null $email    E-mail.
     *
     * @throws \InvalidArgumentException when a required field is empty.
     */
    public function __construct(
        public readonly string $postcode,
        public readonly string $city,
        public readonly string $street,
        public readonly string $phone,
        public readonly ?string $name = null,
        public readonly ?string $company = null,
        public readonly ?string $county = null,
        public readonly ?string $email = null,
    ) {
        foreach (['postcode' => $postcode, 'city' => $city, 'street' => $street, 'phone' => $phone] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("ReaddressingReceiver: {$field} must not be empty.");
            }
        }

        if (trim($name ?? '') === '' && trim($company ?? '') === '') {
            throw new \InvalidArgumentException('ReaddressingReceiver: give a name or a company.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'name' => $this->name,
            'company' => $this->company,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'street' => $this->street,
            'county' => $this->county,
            'email' => $this->email,
            'phone' => $this->phone,
        ]);
    }
}
