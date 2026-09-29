<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The state of a file upload (one entry of `GET /file-command`).
 *
 * Read {@see CommandResult} first. Two UUIDs are involved and easy to mix up: {@see self::$uuid} is
 * the one you chose for the upload command, {@see self::$fileUuid} is the one Furgonetka gave the
 * stored file once the command succeeded. The specification says a shipment takes the "UUID of an
 * uploaded file" (`ShipmentRequest::$photos`, `$filesUuids`, `Duty::$documentsUuids`) and does not
 * say which of the two. For a photo it is {@see self::$fileUuid}, checked against the sandbox: uploaded with the
 * type `packagePhoto` (what Furgonetka's own panel sends), it is valid in `photos`, while the UUID of the command is
 * refused (`noFileIncluded` on `/photos`) like a made-up one. `duty.documents_uuids` accepted any UUID at all, made up
 * ones included, so nothing was learned there; `files_uuids` belongs to Postivo, which the account cannot use.
 *
 * A successful upload proves nothing about the file: the sandbox stored a `.txt` as an invoice and
 * a `nonsense` type without an error. Whether a shipment accepts it is decided when it is used.
 */
final class FileUploadResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors   Every error.
     * @param string|null          $fileUuid The stored file's UUID, present once the command succeeded.
     * @param array<string, mixed> $raw      The whole entry.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly ?string $fileUuid = null,
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

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, Value::string($data, 'file_uuid'), $data);
    }
}
