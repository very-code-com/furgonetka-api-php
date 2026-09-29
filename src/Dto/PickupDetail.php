<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** One booked courier pickup and the shipments it collects (`pickup_details[]`). */
final class PickupDetail
{
    /**
     * @param string|null  $pickupId   Identifier of the pickup.
     * @param list<string> $packageIds The shipments assigned to it.
     */
    public function __construct(
        public readonly ?string $pickupId = null,
        public readonly array $packageIds = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::string($data, 'pickup_id'), Value::stringList($data, 'package_ids'));
    }
}
