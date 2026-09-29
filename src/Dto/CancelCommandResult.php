<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of cancelling shipments (`GET /cancel-command/{uuid}`). Read {@see CommandResult}
 * first.
 *
 * Cancelling can cost money (with GLS, for one), and Furgonetka may schedule it instead of doing it
 * at once: look at each detail's `cancelSuccess` and `scheduledCancelDate`, not only at the status.
 */
final class CancelCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>    $errors  Every error.
     * @param list<CancelDetail> $details What became of each shipment.
     * @param array<string, mixed> $raw  The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly array $details = [],
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
            array_map(CancelDetail::fromArray(...), Value::objectList($data, 'cancel_command_details')),
            $data,
        );
    }

    /**
     * The shipments that were cancelled.
     *
     * @return list<string>
     */
    public function successfulPackageIds(): array
    {
        $ids = [];

        foreach ($this->details as $detail) {
            if ($detail->cancelSuccess === true && $detail->packageId !== null) {
                $ids[] = $detail->packageId;
            }
        }

        return $ids;
    }
}
