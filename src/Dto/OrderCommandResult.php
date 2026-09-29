<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of ordering shipments (`GET /order-commands/{uuid}`).
 *
 * Read {@see CommandResult} first: a carrier that rejects a shipment is an entry in `errors`, and
 * `orderPackages()` still returns normally. The ids of the shipments that were ordered come
 * as numbers from Furgonetka and are strings here, like every shipment id.
 */
final class OrderCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors                      Every error.
     * @param list<string>         $successfullyOrderedPackages The shipments that were ordered.
     * @param array<string, mixed> $raw                         The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly array $successfullyOrderedPackages = [],
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

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, Value::stringList($data, 'successfully_ordered_packages'), $data);
    }

    /**
     * The shipments that were ordered.
     *
     * @return list<string>
     */
    public function successfulPackageIds(): array
    {
        return $this->successfullyOrderedPackages;
    }
}
