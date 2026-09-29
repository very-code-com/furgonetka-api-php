<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** What became of one shipment in a cancellation (`cancel_command_details[]`). */
final class CancelDetail
{
    /**
     * @param string|null             $packageId          The shipment.
     * @param bool|null               $cancelSuccess      Whether it was cancelled.
     * @param string|null             $successMessageType How it was cancelled (`success`, `successWithoutReinsert`, `successAuto`, ...), as sent.
     * @param \DateTimeImmutable|null $scheduledCancelDate When an automatic cancellation is to happen; Furgonetka sends an empty string when there is none.
     */
    public function __construct(
        public readonly ?string $packageId = null,
        public readonly ?bool $cancelSuccess = null,
        public readonly ?string $successMessageType = null,
        public readonly ?\DateTimeImmutable $scheduledCancelDate = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'package_id'),
            Value::bool($data, 'cancel_success'),
            Value::string($data, 'success_message_type'),
            Value::dateTime($data, 'scheduled_cancel_date'),
        );
    }
}
