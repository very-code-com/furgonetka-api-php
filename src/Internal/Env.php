<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal;

/**
 * Reads environment variables the way every `fromEnv()` in this library does.
 *
 * `getenv()`, `$_ENV` and `$_SERVER` are tried in that order and the first non-blank value wins,
 * so it works whether or not `variables_order` exposes `$_ENV` (many PHP-FPM setups do not).
 * The library never reads `.env` files: loading them is the application's job.
 *
 * @internal
 */
final class Env
{
    /** The trimmed value of the variable, or an empty string when it is unset or blank. */
    public static function read(string $name): string
    {
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
