<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Json;

/**
 * Small helpers for building request bodies.
 *
 * @internal
 */
final class Payload
{
    /**
     * The data without its null values and without nested structures that came out empty.
     *
     * Request DTOs send only what was set: a null field means "let Furgonetka's default apply",
     * and an empty nested object (an `additional_services` with no service switched on) means
     * nothing at all. `false`, `0` and `''` are values and stay.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function compact(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
