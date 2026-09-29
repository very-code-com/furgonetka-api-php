<?php

declare(strict_types=1);

/**
 * Receiving Furgonetka's tracking notifications (the webhook).
 *
 *   php examples/11_webhook_endpoint.php             # offline demo: builds two notifications and handles them
 *   php -S 0.0.0.0:8081 examples/11_webhook_endpoint.php    # the same file as a real endpoint
 *
 * As an endpoint it reads the body of the POST, checks it and answers. The token (the "salt" of the
 * checksum) is the one you gave Furgonetka when you saved the webhook; here it comes from
 * FURGONETKA_WEBHOOK_TOKEN.
 *
 * What it shows:
 *   - WebhookEvent::fromJson() and fromXml() read the two formats Furgonetka can send; the format is a
 *     setting of the account (see 16_notifications_and_billing.php);
 *   - verify() recomputes the checksum: md5 of package_id, package_no, partner_order_id, state, description,
 *     datetime and branch, followed by the token. It is Furgonetka's own scheme, not a signature: treat the
 *     notification as a hint, and read fetchTracking() when the state matters (money, stock);
 *   - the answer decides whether the call counts as delivered: HTTP 200 and `{"status":"OK"}` (JSON) or
 *     `<status>OK</status>` (XML). A plain "OK" makes every check end as an error. acknowledgement() builds
 *     the right one for the format the notification came in;
 *   - answer fast and do the work afterwards: Furgonetka waits a few seconds at most, and a failed delivery
 *     shows up in listWebhookLogs() until it is sent again (resendWebhook()).
 */

use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Webhook\WebhookEvent;
use VeryCodeCom\FurgonetkaApi\Webhook\WebhookReply;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Handles one delivery and returns the reply; the caller sends it. Kept free of $_SERVER and echo so it
 * can be tested and called from a framework controller.
 */
function handleWebhook(string $body, string $contentType, string $token): WebhookReply
{
    try {
        $event = str_contains(strtolower($contentType), 'xml') ? WebhookEvent::fromXml($body) : WebhookEvent::fromJson($body);
    } catch (FurgonetkaApiResponseParseException) {
        // Not a notification: a plain 400 is fine, nobody at Furgonetka reads it.
        return new WebhookReply('bad request', 'text/plain', 400);
    }

    if (!$event->verify($token)) {
        // Still a 200 with the ERROR body, as Furgonetka's own example does; log it and move on.
        error_log('Furgonetka webhook: wrong checksum for package ' . (string) $event->packageId);

        return $event->acknowledgement(false);
    }

    // Do the real work in a queue, not here. This only records what arrived.
    error_log(sprintf(
        'Furgonetka webhook: package %s (%s, order %s) is now %s at %s',
        (string) $event->packageId,
        (string) $event->packageNo,
        (string) ($event->partnerOrderId ?? '-'),
        $event->state->value ?? (string) $event->stateValue,
        $event->datetime?->format('Y-m-d H:i:s') ?? '?',
    ));

    return $event->acknowledgement();
}

function send(WebhookReply $reply): void
{
    http_response_code($reply->statusCode);
    header('Content-Type: ' . $reply->contentType);
    echo $reply->body;
}

$token = getenv('FURGONETKA_WEBHOOK_TOKEN') ?: 'tajnasol';

if (PHP_SAPI !== 'cli') {
    send(handleWebhook((string) file_get_contents('php://input'), (string) ($_SERVER['CONTENT_TYPE'] ?? ''), $token));

    return;
}

// Offline demo: notifications signed the way Furgonetka signs them.
function checksum(string $token, string ...$fields): string
{
    return md5(implode('', $fields) . $token);
}

$fields = ['12345', '003203043026', '67890', 'delivered', 'Przesylka doreczona', '2026-09-29 09:19:09', 'Elblag'];
$json = json_encode([
    'package_id' => 12345, 'package_no' => '003203043026', 'partner_order_id' => '67890',
    'tracking' => ['state' => 'delivered', 'description' => 'Przesylka doreczona', 'datetime' => '2026-09-29 09:19:09', 'branch' => 'Elblag'],
    'control' => checksum($token, ...$fields),
], JSON_THROW_ON_ERROR);
$xml = '<?xml version="1.0" encoding="UTF-8"?><Notification><package_id>12345</package_id><package_no>003203043026</package_no>'
    . '<partner_order_id>67890</partner_order_id><tracking><state>delivered</state><description>Przesylka doreczona</description>'
    . '<datetime>2026-09-29 09:19:09</datetime><branch>Elblag</branch></tracking><control>' . checksum($token, ...$fields) . '</control></Notification>';
$tampered = str_replace('delivered', 'transit', $json);

foreach (['JSON' => [$json, 'application/json'], 'XML' => [$xml, 'text/xml'], 'JSON with a changed state' => [$tampered, 'application/json'], 'not a notification' => ['hello', 'text/plain']] as $label => [$body, $type]) {
    $reply = handleWebhook($body, $type, $token);
    echo sprintf("%-27s -> HTTP %d %s %s\n", $label, $reply->statusCode, $reply->contentType, $reply->body);
}

echo "\nThe same notification from a framework: WebhookEvent::fromArray(\$request->toArray(), WebhookFormat::Json).\n";
echo 'Formats: ' . implode(', ', array_map(static fn (WebhookFormat $f): string => $f->value, WebhookFormat::cases())) . PHP_EOL;
