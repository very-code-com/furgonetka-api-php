<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * What an address book entry is made of, to create one, check one (`validateAddressBookEntry()`) or
 * replace one (`replaceAddressBookEntry()`).
 *
 * **Replacing is total.** Furgonetka stores exactly this and nothing else: the sandbox wiped the
 * name, e-mail, address and phone of an entry when it was sent only a new company name. So a request
 * for a replacement holds the whole entry, and the way to change one field is
 * `$entry->toRequest()->with(['city' => 'Krakow'])`.
 *
 * The only rule checked here is the one the sandbox enforced: a name or a company. Everything else,
 * such as the postcode, the e-mail and the phone, is judged by Furgonetka; ask with
 * `validateAddressBookEntry()`. The documentation adds a county for IE, US and CA and a building
 * number for Ukraine, which this class does not check.
 *
 * The IBAN (of the account a cash-on-delivery amount is paid into) is a bank account number, so it
 * is kept out of dumps and traces.
 */
final class AddressBookRequest
{
    /**
     * @param string|null $name           First and last name as one text.
     * @param string|null $company        Company name; required when there is no name.
     * @param string|null $email          E-mail.
     * @param string|null $street         Street and building number (only the street for Ukraine).
     * @param string|null $buildingNumber Building number; Ukraine only.
     * @param string|null $flatNumber     Flat number; Ukraine only.
     * @param string|null $postcode       Postcode.
     * @param string|null $city           City.
     * @param string|null $countryCode    ISO 3166-1 alpha-2.
     * @param string|null $county         County, state or province.
     * @param string|null $nip            Tax id.
     * @param string|null $iban           Account number for cash on delivery.
     * @param string|null $ibanName       Name of the account's owner.
     * @param string|null $phone          Phone number.
     * @param string|null $point          Code of a pickup point, see `Point::identifier()`.
     * @param string|null $service        Carrier of the point.
     * @param bool|null   $defaultPickup  Make it the default sender of the shipping form.
     * @param bool|null   $defaultMyAddress Mark it as "my data".
     * @param bool|null   $defaultSender  Use it as the default "other data on the label".
     * @param bool|null   $isPublic       Switch on the "send to me" link.
     * @param string|null $uuid           The public UUID of the "send to me" link; Furgonetka makes one when it is empty.
     *
     * @throws \InvalidArgumentException when there is neither a name nor a company.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $company = null,
        public readonly ?string $email = null,
        public readonly ?string $street = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $flatNumber = null,
        public readonly ?string $postcode = null,
        public readonly ?string $city = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $county = null,
        public readonly ?string $nip = null,
        #[\SensitiveParameter]
        public readonly ?string $iban = null,
        public readonly ?string $ibanName = null,
        public readonly ?string $phone = null,
        public readonly ?string $point = null,
        public readonly ?string $service = null,
        public readonly ?bool $defaultPickup = null,
        public readonly ?bool $defaultMyAddress = null,
        public readonly ?bool $defaultSender = null,
        public readonly ?bool $isPublic = null,
        public readonly ?string $uuid = null,
    ) {
        if (trim($name ?? '') === '' && trim($company ?? '') === '') {
            throw new \InvalidArgumentException('AddressBookRequest: give a name or a company.');
        }
    }

    /**
     * A copy with some fields changed. The keys are the names of the constructor's parameters
     * (`city`, `postcode`, `isPublic`); `null` clears a field.
     *
     * @param array<string, mixed> $changes
     *
     * @throws \InvalidArgumentException when a key is not a field of the request.
     */
    public function with(array $changes): self
    {
        $fields = get_object_vars($this);
        $unknown = array_diff_key($changes, $fields);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('AddressBookRequest has no field "' . (string) array_key_first($unknown) . '"; the fields are: ' . implode(', ', array_keys($fields)) . '.');
        }

        return new self(...[...$fields, ...$changes]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'name' => $this->name,
            'company' => $this->company,
            'email' => $this->email,
            'street' => $this->street,
            'building_number' => $this->buildingNumber,
            'flat_number' => $this->flatNumber,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'county' => $this->county,
            'nip' => $this->nip,
            'iban' => $this->iban,
            'iban_name' => $this->ibanName,
            'phone' => $this->phone,
            'point' => $this->point,
            'service' => $this->service,
            'default_pickup' => $this->defaultPickup,
            'default_my_address' => $this->defaultMyAddress,
            'default_sender' => $this->defaultSender,
            'is_public' => $this->isPublic,
            'uuid' => $this->uuid,
        ]);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $fields = get_object_vars($this);
        $fields['iban'] = $this->iban === null ? null : '[redacted]';

        return $fields;
    }
}
