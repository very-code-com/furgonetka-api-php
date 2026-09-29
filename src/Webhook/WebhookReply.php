<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Webhook;

/**
 * What a webhook receiver has to answer Furgonetka with: the status, the content type and the body.
 *
 * Furgonetka judges a call by this answer. Anything but `200` and a body that says `OK` in the
 * format of the notification (a plain "OK" included) makes the call count as failed, and the
 * sandbox ended every address check as `error` until the answer had this shape.
 */
final class WebhookReply
{
    /**
     * @param string $body        JSON or XML, as the notification came in.
     * @param string $contentType Value for the `Content-Type` header.
     * @param int    $statusCode  HTTP status; `200` for both an accepted and a refused notification, as in Furgonetka's own examples.
     */
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
        public readonly int $statusCode = 200,
    ) {
    }
}
