<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of adding a shipment to tracking as a command
 * (`GET /add-package-to-tracking-command/{uuid}`), with the carrier Furgonetka worked out.
 *
 * Read {@see CommandResult} first. A number that is already tracked ends as `partial_success` with the
 * error code `package_already_exists_in_tracking` (in Polish, whatever `X-Language` says), not as an
 * exception. Working out the carrier from the number alone took about five seconds on the sandbox.
 */
final class AddToTrackingCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors      Every error.
     * @param string|null          $packageId   Id of the tracked shipment, to give `fetchTracking()`.
     * @param string|null          $carrierName The carrier as sent.
     * @param Carrier|null         $carrier     The carrier; null when unknown or not a member.
     * @param array<string, mixed> $raw         The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly ?string $packageId = null,
        public readonly ?string $carrierName = null,
        public readonly ?Carrier $carrier = null,
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
            Value::string($data, 'package_id'),
            Value::string($data, 'service'),
            Value::enum($data, 'service', Carrier::class),
            $data,
        );
    }
}
