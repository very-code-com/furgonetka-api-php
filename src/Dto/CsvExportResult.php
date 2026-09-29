<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The state of a CSV export of sent shipments (`GET /csv-command/{uuid}`).
 *
 * Read {@see CommandResult} first. A successful export carries `url`, a signed link on Furgonetka's
 * file host (`hash` and `expires` in the query, the account's numeric id in the path); download it
 * with `downloadCsvExport()`, which sends no token. Nothing in the file's name or columns is promised.
 */
final class CsvExportResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors Every error.
     * @param string|null          $url    Where the CSV can be downloaded, once the export is done.
     * @param array<string, mixed> $raw    The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly ?string $url = null,
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

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, Value::string($data, 'url'), $data);
    }
}
