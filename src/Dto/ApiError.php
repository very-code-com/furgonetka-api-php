<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One error reported by Furgonetka.
 *
 * The same entry shows up in three places: the `errors` array of a 4xx/5xx body, the `errors`
 * of a command status (where carrier rejections arrive, as HTTP 200) and the flat 401 body
 * `{"code", "message", "details"}`, which is read as a single entry without a path.
 *
 * `path` points at the field or resource the error is about, e.g. `/receiver/postcode` for a
 * validation error or `/packages/id/90014858123` for a carrier error on one shipment.
 * `code` is a machine-readable identifier; prefer it over matching on `message`, which is
 * translated (see {@see \VeryCodeCom\FurgonetkaApi\Enum\Language}). Command errors use
 * `carrierConnectionError` (the carrier's server could not be reached), `carrierValidationError`
 * (the carrier rejected the shipment) and `unknownError`.
 */
final class ApiError implements \Stringable
{
    public function __construct(
        public readonly ?string $message = null,
        public readonly ?string $code = null,
        public readonly ?string $path = null,
        public readonly ?string $details = null,
    ) {
    }

    /**
     * Lenient: every field is optional, unknown fields are ignored.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            message: Value::string($data, 'message'),
            code: Value::string($data, 'code'),
            path: Value::string($data, 'path'),
            details: Value::string($data, 'details'),
        );
    }

    /** One line for logs and CLI output: `message: details [code] (path)`, leaving out what is absent. */
    public function __toString(): string
    {
        $text = $this->message ?? $this->code ?? 'Unknown error';

        if ($this->details !== null && $this->details !== $this->message) {
            $text .= ': ' . $this->details;
        }

        if ($this->code !== null && $this->code !== $text) {
            $text .= ' [' . $this->code . ']';
        }

        if ($this->path !== null) {
            $text .= ' (' . $this->path . ')';
        }

        return $text;
    }
}
