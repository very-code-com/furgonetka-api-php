<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Json;

/**
 * Type-safe readers for values pulled out of a decoded Furgonetka JSON response.
 *
 * Response DTOs are built from `array<string, mixed>`, so every field needs a cast that PHPStan
 * can follow and that survives what a live API does to a documented schema: sending `null` where
 * the schema promises a value, sending a number as a string (or the other way round), and
 * growing new fields every few days. Every reader here returns null rather than throwing when a
 * field is absent or unusable, so one unexpected field cannot break an otherwise good response.
 *
 * Two quirks of Furgonetka's PHP backend are absorbed here:
 *  - Shipment ids are strings in requests and sometimes integers in responses. {@see self::string()}
 *    accepts both, so an id is a string everywhere in the library.
 *  - An empty map is serialised as `[]`, not `{}`. {@see self::object()} therefore treats an
 *    empty list as an empty object.
 *
 * @internal
 */
final class Value
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Booleans arrive as real JSON booleans, but the API also documents flags as `0`/`1` and a
     * few fields as tri-state integers, so `0`, `1` and their string forms are accepted. Any
     * other number is not a flag and reads as null.
     *
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * Parse a date or date-time; see {@see DateFormat::parse()} for the shapes handled.
     *
     * @param array<string, mixed> $data
     */
    public static function dateTime(array $data, string $key): ?\DateTimeImmutable
    {
        return DateFormat::parse(self::string($data, $key));
    }

    /**
     * A nested object, or null when the key is absent or holds anything but an object. An empty
     * array counts as an empty object (see the class docblock).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A list of nested objects, skipping any entry that is not an object. Returns an empty
     * list when the key is absent, which is what callers want for an omitted collection.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item) && ($item === [] || !array_is_list($item))) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * An object whose values are objects, such as the `parcels` of a tracking answer keyed by
     * waybill number. Entries that are not objects are skipped.
     *
     * PHP turns a key like `"1582893168850"` into the integer 1582893168850 the moment it is used
     * as an array key, so a caller that needs the key as text casts it: `(string) $key`.
     *
     * @param array<string, mixed> $data
     * @return array<int|string, array<string, mixed>>
     */
    public static function objectMap(array $data, string $key): array
    {
        $map = [];

        foreach (self::object($data, $key) ?? [] as $name => $item) {
            if (is_array($item) && ($item === [] || !array_is_list($item))) {
                /** @var array<string, mixed> $item */
                $map[$name] = $item;
            }
        }

        return $map;
    }

    /**
     * A list of strings, skipping non-scalar entries. A single string is read as a one-item
     * list, since a field that holds "one or many" is common.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            } elseif (is_int($item) || is_float($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    /**
     * A backed enum case, or null when the value is absent or is a member Furgonetka added after
     * this library was released. Unknown values are deliberately not an error: an API that grows
     * a new carrier or status must not break a client that only reads the ones it knows. DTOs
     * keep the raw string next to the enum so the unknown value is still available.
     *
     * @template T of \BackedEnum
     * @param array<string, mixed> $data
     * @param class-string<T> $enum
     * @return T|null
     */
    public static function enum(array $data, string $key, string $enum): ?\BackedEnum
    {
        $value = self::string($data, $key);

        return $value !== null ? $enum::tryFrom($value) : null;
    }
}
