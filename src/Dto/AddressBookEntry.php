<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\InvoiceDataType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * An entry of the account's address book: a sender, a receiver, a pickup point or the data of an
 * invoice, with the flags that say where Furgonetka fills it in by default.
 *
 * Furgonetka sends an empty string for what is not set, and those read as null here. It normalises
 * what it stores: it turned the phone "500200300" into "+48500200300" and the city "Krakow" into
 * "Kraków". The phone comes twice, as text and as {@see PhoneNumber}. `pointData` is a
 * {@see Point} when the entry is a pickup point.
 *
 * The entry does not carry the IBAN and the account owner's name that a request can set, so they
 * cannot be read back, and {@see self::toRequest()} cannot copy them.
 */
final class AddressBookEntry
{
    /**
     * @param int|null              $id                Id of the entry.
     * @param PointService|null     $service           Carrier of the point, when the entry is a point; null when it is not a member.
     * @param string|null           $serviceValue      Carrier as sent.
     * @param string|null           $fullName          First and last name as one text.
     * @param string|null           $name              First name.
     * @param string|null           $surname           Last name.
     * @param string|null           $company           Company name.
     * @param string|null           $email             E-mail.
     * @param string|null           $street            Street and building number (only the street for Ukraine).
     * @param string|null           $postcode          Postcode.
     * @param string|null           $city              City.
     * @param string|null           $countryCode       ISO 3166-1 alpha-2.
     * @param string|null           $county            County, state or province.
     * @param string|null           $phone             Phone number as stored, with the prefix.
     * @param PhoneNumber|null      $phoneNumber       The phone split into prefix, number and country.
     * @param string|null           $point             Code of the pickup point.
     * @param string|null           $pointLabel        The point's address as one text.
     * @param string|null           $pointType         The carrier's label of the point kind.
     * @param Point|null            $pointData         The pickup point in full.
     * @param bool|null             $defaultPickup     The default sender in the shipping form.
     * @param bool|null             $defaultSender     The default "other data on the label".
     * @param bool|null             $defaultMyAddress  The default "my data".
     * @param bool|null             $invoiceData       Whether the entry is invoice data.
     * @param InvoiceDataType|null  $invoiceDataType   Person or company; null when unset or not a member.
     * @param string|null           $invoiceDataTypeValue Invoice data type as sent.
     * @param string|null           $nip               Tax id.
     * @param string|null           $sendToMeLink      The "send to me" link, present when the entry is public.
     * @param bool|null             $isPublic          Whether the "send to me" link is active.
     * @param string|null           $uuid              Public UUID of the entry; it forms the "send to me" link.
     * @param string|null           $buildingNumber    Building number (Ukraine).
     * @param string|null           $flatNumber        Flat number (Ukraine).
     * @param array<string, mixed>  $raw               The whole object as Furgonetka sent it.
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?PointService $service = null,
        public readonly ?string $serviceValue = null,
        public readonly ?string $fullName = null,
        public readonly ?string $name = null,
        public readonly ?string $surname = null,
        public readonly ?string $company = null,
        public readonly ?string $email = null,
        public readonly ?string $street = null,
        public readonly ?string $postcode = null,
        public readonly ?string $city = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $county = null,
        public readonly ?string $phone = null,
        public readonly ?PhoneNumber $phoneNumber = null,
        public readonly ?string $point = null,
        public readonly ?string $pointLabel = null,
        public readonly ?string $pointType = null,
        public readonly ?Point $pointData = null,
        public readonly ?bool $defaultPickup = null,
        public readonly ?bool $defaultSender = null,
        public readonly ?bool $defaultMyAddress = null,
        public readonly ?bool $invoiceData = null,
        public readonly ?InvoiceDataType $invoiceDataType = null,
        public readonly ?string $invoiceDataTypeValue = null,
        public readonly ?string $nip = null,
        public readonly ?string $sendToMeLink = null,
        public readonly ?bool $isPublic = null,
        public readonly ?string $uuid = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $flatNumber = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $phone = Value::object($data, 'phone_number_data');
        $point = Value::object($data, 'point_data');

        return new self(
            id: Value::int($data, 'id'),
            service: Value::enum($data, 'service', PointService::class),
            serviceValue: Value::string($data, 'service'),
            fullName: Value::string($data, 'full_name'),
            name: Value::string($data, 'name'),
            surname: Value::string($data, 'surname'),
            company: Value::string($data, 'company'),
            email: Value::string($data, 'email'),
            street: Value::string($data, 'street'),
            postcode: Value::string($data, 'postcode'),
            city: Value::string($data, 'city'),
            countryCode: Value::string($data, 'country_code'),
            county: Value::string($data, 'county'),
            phone: Value::string($data, 'phone'),
            phoneNumber: $phone !== null ? PhoneNumber::fromArray($phone) : null,
            point: Value::string($data, 'point'),
            pointLabel: Value::string($data, 'point_label'),
            pointType: Value::string($data, 'point_type'),
            pointData: $point !== null ? Point::fromArray($point) : null,
            defaultPickup: Value::bool($data, 'default_pickup'),
            defaultSender: Value::bool($data, 'default_sender'),
            defaultMyAddress: Value::bool($data, 'default_my_address'),
            invoiceData: Value::bool($data, 'invoice_data'),
            invoiceDataType: Value::enum($data, 'invoice_data_type', InvoiceDataType::class),
            invoiceDataTypeValue: Value::string($data, 'invoice_data_type'),
            nip: Value::string($data, 'nip'),
            sendToMeLink: Value::string($data, 'send_to_me_link'),
            isPublic: Value::bool($data, 'is_public'),
            uuid: Value::string($data, 'uuid'),
            buildingNumber: Value::string($data, 'building_number'),
            flatNumber: Value::string($data, 'flat_number'),
            raw: $data,
        );
    }

    /**
     * A request that holds what this entry holds, to change a field and send it back with
     * `replaceAddressBookEntry()`: `$client->replaceAddressBookEntry($entry, $entry->toRequest()->with(['city' => 'Krakow']))`.
     *
     * The replacement is total (see `replaceAddressBookEntry()`), so whatever this request does not
     * carry is lost. That is the IBAN and the account owner's name, which Furgonetka never sends back:
     * set them with `with()` again if the entry had them. The name is the full name when there is one,
     * else the first and last name.
     *
     * @throws \InvalidArgumentException when the entry has neither a name nor a company, which the API refuses.
     */
    public function toRequest(): AddressBookRequest
    {
        $name = $this->fullName ?? (trim(($this->name ?? '') . ' ' . ($this->surname ?? '')) ?: null);

        return new AddressBookRequest(
            name: $name,
            company: $this->company,
            email: $this->email,
            street: $this->street,
            buildingNumber: $this->buildingNumber,
            flatNumber: $this->flatNumber,
            postcode: $this->postcode,
            city: $this->city,
            countryCode: $this->countryCode,
            county: $this->county,
            nip: $this->nip,
            phone: $this->phone,
            point: $this->point,
            service: $this->serviceValue,
            defaultPickup: $this->defaultPickup,
            defaultMyAddress: $this->defaultMyAddress,
            defaultSender: $this->defaultSender,
            isPublic: $this->isPublic,
            uuid: $this->uuid,
        );
    }

    /**
     * The whole object as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
