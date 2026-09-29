<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of creating a shipment as a command (`GET /create-package-command/{uuid}`).
 *
 * Read {@see CommandResult} first. A successful command names the shipment in `packageId`; the
 * command may instead answer with `redirectUrl`, where a person finishes the shipment in the
 * browser (the specification says so; the sandbox never did). A failed one carries the errors of
 * the shipment, and their paths are indexed, not named: `/0/postcode` is the postcode of the
 * receiver, `/0`, `/1` and `/2` are the three missing top-level fields. Match `code`, and use
 * `validateRemotely()` when you need paths a person can read.
 */
final class CreatePackageCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors      Every error.
     * @param string|null          $packageId   The shipment that was created; null until it is.
     * @param string|null          $redirectUrl Where a person finishes the shipment, when Furgonetka wants that.
     * @param array<string, mixed> $raw         The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly ?string $packageId = null,
        public readonly ?string $redirectUrl = null,
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

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, Value::string($data, 'package_id'), Value::string($data, 'redirect_url'), $data);
    }

    /** True when the shipment is not complete until a person opens {@see self::$redirectUrl}. */
    public function needsRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
