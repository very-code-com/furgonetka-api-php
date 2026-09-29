<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of checking the shipments in the cart again (`GET /revalidate-command/{uuid}`).
 *
 * Read {@see CommandResult} first. `success` says whether the check passed; when it did not, the
 * reasons are in `errors`. Furgonetka answers 404 to the request itself when the cart is empty.
 */
final class RevalidateResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors  Every error.
     * @param bool|null            $success Whether the shipments passed the check; null until it is finished.
     * @param array<string, mixed> $raw     The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly ?bool $success = null,
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

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, Value::bool($data, 'success'), $data);
    }
}
