<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Language of the messages Furgonetka puts in its responses (validation errors, carrier errors,
 * service names), chosen with the `X-Language` request header.
 *
 * Furgonetka documents `en_GB` as the only alternative to its Polish default and treats any
 * other value exactly like a missing header, so an unsupported language would fail silently.
 * Only the two values that work are offered here.
 *
 * The library sends the header on every request and defaults to English, so exception messages
 * read the same as the rest of the library. {@see \VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig}
 */
enum Language: string
{
    case English = 'en_GB';
    case Polish = 'pl_PL';
}
