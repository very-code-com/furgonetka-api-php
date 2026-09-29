<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Http;

/**
 * Masks credentials in what the library sends and receives, before it can reach a log line, an
 * exception message or a debug report.
 *
 * Furgonetka's secrets travel in three places:
 *  - the `Authorization` header (`Bearer <access token>`, or `Basic <client id:secret>` on the
 *    token endpoint);
 *  - the form body of the token and 2FA endpoints (`password`, `client_secret`, `refresh_token`,
 *    the authorization `code`, `twoFactorCode`);
 *  - the JSON body of the token endpoint's answer (`access_token`, `refresh_token`).
 *
 * Bodies are masked with text substitution, not by decoding and re-encoding, so the rest of the
 * body stays byte for byte as it was and a truncated or malformed body is still covered. If a
 * substitution fails, the whole body is withheld: failing open would defeat the purpose.
 *
 * @internal
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /** Keys whose values are secrets in a request body (form or JSON). */
    public const REQUEST_KEYS = ['password', 'client_secret', 'refresh_token', 'access_token', 'code', 'twoFactorCode', 'webhookToken', 'iban'];

    /**
     * Keys whose values are secrets in a response body. Narrower than for requests on purpose:
     * an error body has a `code` field that is the very thing a debug report is read for. `token`
     * is the checksum salt of the webhook settings.
     */
    public const RESPONSE_KEYS = ['access_token', 'refresh_token', 'token'];

    private const WITHHELD = '[body withheld: it could not be checked for credentials]';

    /** Headers whose whole value is a credential. */
    private const SENSITIVE_HEADERS = ['authorization', 'proxy-authorization'];

    /**
     * The value to show for a header: unchanged, or for a credential header the scheme kept and
     * the credential replaced (`Bearer [redacted]`), which says what kind of auth was used
     * without giving any of it away.
     */
    public static function header(string $name, string $value): string
    {
        if (!in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
            return $value;
        }

        $space = strpos($value, ' ');

        return $space === false ? self::MASK : substr($value, 0, $space) . ' ' . self::MASK;
    }

    /**
     * Mask the secrets in a body, choosing the encoding from its content type. With a missing or
     * unknown type both encodings are tried, since guessing wrong would leave a secret in.
     *
     * @param list<string> $keys
     */
    public static function body(string $body, string $contentType, array $keys): string
    {
        if ($body === '') {
            return $body;
        }

        $type = strtolower($contentType);

        if (str_contains($type, 'json')) {
            return self::json($body, $keys);
        }

        if (str_contains($type, 'x-www-form-urlencoded')) {
            return self::form($body, $keys);
        }

        $masked = self::json($body, $keys);

        return $masked === self::WITHHELD ? $masked : self::form($masked, $keys);
    }

    /**
     * Replace the string value of each listed key, at any depth: `"refresh_token": "abc"`
     * becomes `"refresh_token": "[redacted]"`.
     *
     * @param list<string> $keys
     */
    public static function json(string $body, array $keys): string
    {
        if (!self::mentionsAny($body, $keys)) {
            return $body;
        }

        // Possessive quantifiers keep this linear and free of backtracking limits on long values.
        $pattern = '/("' . self::alternation($keys) . '"\s*:\s*)"(?:[^"\\\\]++|\\\\.)*+"/i';

        return preg_replace($pattern, '$1"' . self::MASK . '"', $body) ?? self::WITHHELD;
    }

    /**
     * Replace the value of each listed field in an `application/x-www-form-urlencoded` body:
     * `grant_type=password&password=abc` becomes `grant_type=password&password=[redacted]`.
     *
     * @param list<string> $keys
     */
    public static function form(string $body, array $keys): string
    {
        if (!self::mentionsAny($body, $keys)) {
            return $body;
        }

        $pattern = '/((?:^|&)' . self::alternation($keys) . '=)[^&]*+/i';

        return preg_replace($pattern, '$1' . self::MASK, $body) ?? self::WITHHELD;
    }

    /** @param list<string> $keys */
    private static function mentionsAny(string $body, array $keys): bool
    {
        foreach ($keys as $key) {
            if (stripos($body, $key) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The keys as one non-capturing group, quoted for a `/`-delimited pattern. The group is built
     * here, not in the patterns, so a caller can put the result anywhere a single token fits.
     *
     * @param list<string> $keys
     */
    private static function alternation(array $keys): string
    {
        return '(?:' . implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys)) . ')';
    }
}
