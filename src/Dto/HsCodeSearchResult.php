<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The outcome of a search for HS codes (`GET /packages/duty/hs-codes-autocomplete-command/{uuid}`).
 *
 * Read {@see CommandResult} first. The list is empty until the command is successful, and a code can
 * appear more than once with different descriptions (a search for "shoes" gave 178 entries, many
 * with the same code). A carrier the account may not use ends as `error` with the message "Brak
 * uprawnień do danego zasobu API." (in Polish) and no code, seen for Meest on the sandbox.
 */
final class HsCodeSearchResult extends CommandResult
{
    /**
     * @param list<ApiError>       $errors  Every error.
     * @param list<HsCode>         $hsCodes The codes found, in the order Furgonetka sent them.
     * @param array<string, mixed> $raw     The whole answer.
     */
    public function __construct(
        ?string $uuid = null,
        ?CommandStatus $status = null,
        ?string $statusValue = null,
        ?\DateTimeImmutable $datetimeChange = null,
        array $errors = [],
        public readonly array $hsCodes = [],
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
        $result = Value::object($data, 'result') ?? [];

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, array_map(HsCode::fromArray(...), Value::objectList($result, 'hsCodes')), $data);
    }

    /**
     * The distinct codes, each once, in the order they first appeared.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];

        foreach ($this->hsCodes as $code) {
            if ($code->hsCode !== null) {
                $codes[$code->hsCode] = $code->hsCode;
            }
        }

        return array_values($codes);
    }
}
