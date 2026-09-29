<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of booking a courier pickup (`GET /pickup-commands/{uuid}`). Read {@see CommandResult}
 * first: a failed booking is an entry in `errors`, not an exception.
 */
final class PickupCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>    $errors        Every error.
     * @param list<PickupDetail> $pickupDetails The pickups that were booked, with their shipments.
     * @param array<string, mixed> $raw        The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly array $pickupDetails = [],
        array $raw = [],
    ) {
        parent::__construct($uuid, $status, $statusValue, $datetimeChange, $errors, $raw);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$uuid, $status, $statusValue, $datetimeChange, $errors] = self::common($data);

        return new self(
            $uuid,
            $status,
            $statusValue,
            $datetimeChange,
            $errors,
            array_map(PickupDetail::fromArray(...), Value::objectList($data, 'pickup_details')),
            $data,
        );
    }

    /**
     * The shipments that have a pickup.
     *
     * @return list<string>
     */
    public function successfulPackageIds(): array
    {
        $ids = [];

        foreach ($this->pickupDetails as $detail) {
            foreach ($detail->packageIds as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
