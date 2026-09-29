<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Json;

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;

/**
 * Decodes a successful Furgonetka response body.
 *
 * Every JSON answer in the specification is an object at the top level (there is no bare array
 * response) and there is no envelope to unwrap, so this is decoding plus a shape check.
 *
 * Exceptions thrown here carry no raw body on purpose. The client attaches the (redacted) body
 * to an exception in debug mode only, in one place; a parser that attached it itself would
 * bypass that switch and could put a token response into a log. Messages therefore name the
 * operation and the problem, never the content.
 *
 * @internal
 */
final class ResponseParser
{
    /**
     * Decode a JSON object body.
     *
     * Integers too large for PHP are kept as strings instead of degrading into floats, which
     * would corrupt a long identifier.
     *
     * @return array<string, mixed>
     * @throws FurgonetkaApiResponseParseException when the body is empty, not JSON, or not an object.
     */
    public function decode(
        #[\SensitiveParameter]
        string $body,
        string $operation,
    ): array {
        $trimmed = trim($body);

        if ($trimmed === '') {
            throw new FurgonetkaApiResponseParseException(
                "Furgonetka returned an empty body for {$operation}, expected JSON."
            );
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            // The JsonException is deliberately not chained as the cause: its stack trace holds the
            // arguments of json_decode(), that is the whole body, and no attribute can mask the
            // arguments of an internal function. Its message is all there is to keep.
            throw new FurgonetkaApiResponseParseException(
                "Furgonetka returned malformed JSON for {$operation}: {$e->getMessage()}"
            );
        }

        // "{}" and "[]" both decode to an empty PHP array; only the first is an object.
        $isObject = is_array($decoded) && ($decoded === [] ? str_starts_with($trimmed, '{') : !array_is_list($decoded));

        if (!$isObject) {
            throw new FurgonetkaApiResponseParseException(
                "Furgonetka returned a JSON value that is not an object for {$operation}."
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
