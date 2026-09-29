<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\DispositionStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The readdressing order of a shipment (`readdressing_disposition` of `GET /packages/{id}`): the new receiver and
 * where the order stands, present once someone has ordered a readdressing for it.
 *
 * It was null on every shipment of the sandbox, because the sandbox refused every readdressing, so this shape
 * follows the specification and has never been seen filled in. {@see Package::readdressingDisposition()} is null
 * for a shipment nobody has readdressed. The receiver's fields are flat here, and the phone is plain text, unlike
 * the addresses of the shipment itself.
 */
final class ReaddressingDisposition
{
    /**
     * @param DispositionStatus|null  $status      Where the order stands; null when it is not a member.
     * @param string|null             $statusValue Status as sent (`new` waits for payment, `paid`).
     * @param \DateTimeImmutable|null $datetime    When the order was created.
     * @param string|null             $name        The new receiver's name.
     * @param string|null             $company     Company.
     * @param string|null             $street      Street, house and flat number.
     * @param string|null             $postcode    Postcode.
     * @param string|null             $city        City.
     * @param string|null             $countryCode ISO 3166-1 alpha-2.
     * @param string|null             $county      County, state or province, for Ireland, the USA and Canada.
     * @param string|null             $email       E-mail.
     * @param string|null             $phone       Phone number as text.
     * @param string|null             $point       Pickup point, for a delivery to one.
     * @param array<string, mixed>    $raw         The whole object.
     */
    public function __construct(
        public readonly ?DispositionStatus $status = null,
        public readonly ?string $statusValue = null,
        public readonly ?\DateTimeImmutable $datetime = null,
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
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: Value::enum($data, 'status', DispositionStatus::class),
            statusValue: Value::string($data, 'status'),
            datetime: Value::dateTime($data, 'datetime'),
            name: Value::string($data, 'name'),
            company: Value::string($data, 'company'),
            street: Value::string($data, 'street'),
            postcode: Value::string($data, 'postcode'),
            city: Value::string($data, 'city'),
            countryCode: Value::string($data, 'country_code'),
            county: Value::string($data, 'county'),
            email: Value::string($data, 'email'),
            phone: Value::string($data, 'phone'),
            point: Value::string($data, 'point'),
            raw: $data,
        );
    }

    /** True when the order is created and not paid yet. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === DispositionStatus::New;
    }

    /** True when the order is paid. */
    public function isPaid(): bool
    {
        return $this->status === DispositionStatus::Paid;
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
