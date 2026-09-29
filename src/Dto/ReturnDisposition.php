<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\DispositionStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The return order of a shipment (`return_disposition` of `GET /packages/{id}`), present once someone has
 * ordered a return for it.
 *
 * It was null on every shipment of the sandbox, because the sandbox refused every return, so this shape
 * follows the specification and has never been seen filled in. {@see Package::returnDisposition()} is null
 * for a shipment nobody has returned.
 */
final class ReturnDisposition
{
    /**
     * @param DispositionStatus|null $status      Where the order stands; null when it is not a member.
     * @param string|null            $statusValue Status as sent (`new` waits for payment, `paid`).
     * @param \DateTimeImmutable|null $datetime   When the order was created.
     * @param array<string, mixed>   $raw         The whole object.
     */
    public function __construct(
        public readonly ?DispositionStatus $status = null,
        public readonly ?string $statusValue = null,
        public readonly ?\DateTimeImmutable $datetime = null,
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
