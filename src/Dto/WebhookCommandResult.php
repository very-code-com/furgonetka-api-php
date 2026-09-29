<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

/**
 * The outcome of checking a webhook address or sending a notification again
 * (`GET .../webhook/check-command/{uuid}`, `GET .../webhook/resend-command/{uuid}`).
 *
 * Read {@see CommandResult}; the answer has nothing besides its parts. A check of an address that
 * cannot be reached ended as `error` with an empty `errors` list on the sandbox, so `hasFailed()`
 * is the signal and there is no reason to read. A check does not save the address.
 */
final class WebhookCommandResult extends CommandResult
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$uuid, $status, $statusValue, $datetimeChange, $errors] = self::common($data);

        return new self($uuid, $status, $statusValue, $datetimeChange, $errors, $data);
    }
}
