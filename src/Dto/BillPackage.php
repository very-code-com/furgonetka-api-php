<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A shipment an account operation is about. `status` is the carrier's text (in Polish, or the
 * account's language), `state` Furgonetka's own short word (`anulowana`, `zwrotna`, `doreczona`).
 */
final class BillPackage
{
    /**
     * @param string|null  $packageNo   The carrier's waybill number.
     * @param string|null  $packageId   Furgonetka's id of the shipment.
     * @param string|null  $status
     * @param string|null  $state       The status as Furgonetka's word for it.
     * @param string|null  $service     Carrier as sent.
     * @param Carrier|null $carrier     Carrier; null when it is not a member.
     * @param int|null     $userId      The user who made the shipment.
     */
    public function __construct(
        public readonly ?string $packageNo = null,
        public readonly ?string $packageId = null,
        public readonly ?string $status = null,
        public readonly ?string $state = null,
        public readonly ?string $service = null,
        public readonly ?Carrier $carrier = null,
        public readonly ?int $userId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'package_no'),
            Value::string($data, 'package_id'),
            Value::string($data, 'status'),
            Value::string($data, 'state'),
            Value::string($data, 'service'),
            Value::enum($data, 'service', Carrier::class),
            Value::int($data, 'user_id'),
        );
    }
}
