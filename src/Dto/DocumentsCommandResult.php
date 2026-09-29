<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of preparing documents (`GET /documents-command/{uuid}`). Read {@see CommandResult}
 * first.
 *
 * When it is done, `url` is where the file is: a PDF, a ZPL or EPL text, or a ZIP when there are
 * several documents that cannot go into one file. The link is signed and expires after about an
 * hour (`expires` in the query), and it needs no login, so `downloadDocuments()` sends none.
 */
final class DocumentsCommandResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors Every error.
     * @param string|null          $url    Where to download the prepared file; null until the command is done.
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
