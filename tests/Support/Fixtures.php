<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Loads the real bodies in tests/Fixtures. They were captured on the sandbox (or, where a folder or
 * a test says so, copied from Furgonetka's documentation) with personal data scrubbed; nothing in
 * them is invented.
 */
final class Fixtures
{
    /** The raw text of a fixture, e.g. `packages/created`. */
    public static function raw(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json');

        return $body !== false ? $body : throw new \LogicException("Missing fixture: {$name}");
    }

    /** The text of an XML fixture, e.g. `webhook/doc-example`. */
    public static function xml(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.xml');

        return $body !== false ? $body : throw new \LogicException("Missing fixture: {$name}");
    }

    /**
     * A fixture, decoded.
     *
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $data = json_decode(self::raw($name), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \LogicException("Fixture {$name} is not a JSON object.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** The fixture as the answer of the API, with the content type Furgonetka sends. */
    public static function response(string $name, int $status = 200): TransportResponse
    {
        return new TransportResponse($status, self::raw($name), ['content-type' => 'application/json', 'x-ratelimit-limit' => '500', 'x-ratelimit-remaining' => '499', 'x-ratelimit-retry-after' => '0']);
    }

    /** An answer with no body, such as the 204 of a delete. */
    public static function empty(int $status = 204): TransportResponse
    {
        return new TransportResponse($status, '', ['x-ratelimit-limit' => '500', 'x-ratelimit-remaining' => '499', 'x-ratelimit-retry-after' => '0']);
    }
}
