<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Format of the webhook notifications Furgonetka sends (`format` of the webhook settings).
 *
 * `WebhookEvent::fromJson()` and `fromXml()` read the notifications in either format.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum WebhookFormat: string
{
    /** JSON. */
    case Json = 'json';

    /** XML. */
    case Xml = 'xml';
}
