<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal;

/**
 * UUID version 4 from `random_bytes()`, without a dependency on ramsey/uuid.
 *
 * Furgonetka's commands are keyed by a UUID the client makes up, so the client has it before the
 * request is sent and can hand it back even when the request never got an answer.
 *
 * @internal
 */
final class Uuid
{
    /** A random (version 4) UUID in its canonical lower-case form. */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** True for any UUID in the canonical 8-4-4-4-12 form, of any version. */
    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }
}
