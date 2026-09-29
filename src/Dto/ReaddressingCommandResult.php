<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The state of a readdressing (`GET /packages/readdressing/{uuid}`).
 *
 * Read {@see CommandResult} first. The command status says whether Furgonetka has processed the
 * order; whether it is paid is `details->status`.
 */
final class ReaddressingCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>            $errors  Every error.
     * @param ReaddressingDetails|null  $details Receiver, price, payment state and shipments.
     * @param array<string, mixed>      $raw     The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly ?ReaddressingDetails $details = null,
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
        $details = Value::object($data, 'details');

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, $details !== null ? ReaddressingDetails::fromArray($details) : null, $data);
    }
}
