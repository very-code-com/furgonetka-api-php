<?php

declare(strict_types=1);

/**
 * Notification settings, the webhook, and the account's balance.
 *
 *   php examples/16_notifications_and_billing.php
 *   FURGONETKA_WEBHOOK_TOKEN=... php examples/16_notifications_and_billing.php --webhook https://shop.example/furgonetka --save
 *
 * Without --save it only reads. --save writes the account's settings: it turns the webhook on for every
 * tracking state, and it sets the address only after Furgonetka has called it and got a good answer (see
 * 11_webhook_endpoint.php for a receiver). The token is the checksum salt; it comes from the environment so
 * it does not end up in your shell history.
 *
 * What it shows:
 *   - three reads (general options, shipping notifications per state, webhook settings) and ONE write:
 *     saveNotificationSettings() REPLACES EVERYTHING, so a request is built from the reads,
 *     `NotificationSettingsRequest::from(...)`, and you change what you want with withOrder(), withTracking(),
 *     withEmail() and withWebhook(). Leaving something out changes it (omitting the e-mail options changed the
 *     account's general settings on the sandbox);
 *   - checkWebhook() asks Furgonetka to call the address and reports success or error; an unreachable address
 *     ends as an error with nothing listed, and the reply of your receiver is the only clue. A webhook can only
 *     be saved after its address passed the check;
 *   - listWebhookLogs() holds the deliveries that FAILED (a good one only moves `lastSuccess`), with what the
 *     receiver answered; resendWebhook() sends one again and removes it from the log when it goes through;
 *   - fetchBalance() lists operations newest first with the balance; dates are days (YYYY-MM-DD) and an `items`
 *     above 1000 would silently read as 10, which BalanceQuery refuses.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\NotificationSettingsRequest;
use VeryCodeCom\FurgonetkaApi\Dto\OrderNotificationRule;
use VeryCodeCom\FurgonetkaApi\Dto\TrackingNotificationRule;
use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

exampleRun(static function (): void {
    $client = exampleClient();

    exampleHeading('E-mail options');
    foreach ($client->fetchGeneralNotifications() as $option) {
        printf("%-30s %s\n", (string) $option->key, $option->enabled === null ? 'not set' : ($option->enabled ? 'on' : 'off'));
    }

    exampleHeading('Per tracking state: order (sms, e-mail, push, webhook) and tracking (push, webhook)');
    foreach ($client->fetchShippingNotifications() as $group) {
        $on = array_keys(array_filter(['sms' => $group->order->sms, 'email' => $group->order->email, 'push' => $group->order->push, 'webhook' => $group->order->webhook]));
        printf("%-22s order: %-24s tracking webhook: %s\n", (string) $group->key, $on === [] ? '-' : implode(',', $on), $group->tracking->webhook === true ? 'on' : 'off');
    }

    exampleHeading('Webhook');
    $webhook = $client->fetchWebhookSettings();
    echo $webhook->isConfigured() ? "Set: {$webhook->url} ({$webhook->formatValue}); the token is hidden in dumps.\n" : "None.\n";

    $logs = $client->listWebhookLogs();
    echo count($logs) . " failed deliveries in the log; last success: " . ($logs->lastSuccess?->format('Y-m-d H:i') ?? 'never') . PHP_EOL;

    foreach ($logs as $log) {
        printf("  #%s %s %s -> HTTP %s: %s\n", (string) $log->id, $log->datetimeSend?->format('Y-m-d H:i') ?? '?', (string) $log->trackingStateValue, (string) $log->httpCode, (string) $log->webhookResponse);
    }

    if (exampleFlag('--webhook')) {
        $url = exampleArgument(1) ?? throw new \RuntimeException('Usage: --webhook <https url> [--save], with FURGONETKA_WEBHOOK_TOKEN in the environment.');
        $token = getenv('FURGONETKA_WEBHOOK_TOKEN') ?: throw new \RuntimeException('Set FURGONETKA_WEBHOOK_TOKEN (the checksum salt).');

        exampleHeading("Checking {$url}");
        $check = $client->checkWebhook($url, $token, WebhookFormat::Json);
        echo $check->isSuccessful() ? "Furgonetka called it and got a good answer.\n" : "The check failed. Does the receiver answer HTTP 200 and {\"status\":\"OK\"}? Furgonetka lists no reason.\n";

        if ($check->isSuccessful() && exampleFlag('--save')) {
            $request = NotificationSettingsRequest::from($client->fetchShippingNotifications(), $client->fetchGeneralNotifications(), $webhook);

            foreach ($request->orderNotifications as $rule) {
                $request = $request->withOrder(new OrderNotificationRule($rule->state, true, $rule->push, $rule->sms, $rule->email));
            }

            foreach ($request->trackingNotifications as $rule) {
                $request = $request->withTracking(new TrackingNotificationRule($rule->state, true, $rule->push));
            }

            $client->saveNotificationSettings($request->withWebhook($url, $token, WebhookFormat::Json));
            echo "Saved: the webhook is on for every state. Try it on the sandbox with: php examples/07_tracking.php <id> --simulate\n";
            echo 'Delivered is ' . TrackingState::Delivered->value . "; a notification arrives about a minute after the change.\n";
        }
    }

    exampleHeading('Balance');
    $balance = $client->fetchBalance(new BalanceQuery(items: 5));
    printf("Balance: %.2f %s (%d operations in all)\n", (float) $balance->balance, (string) $balance->currency, (int) $balance->billsCount);

    foreach ($balance as $bill) {
        printf("  %s  %9.2f  %-14s %s\n", $bill->datetime?->format('Y-m-d H:i') ?? '?', (float) $bill->amount, (string) $bill->typeValue, (string) ($bill->descriptionFormatted ?? $bill->description));
    }

    $spent = $client->fetchBalance(new BalanceQuery(items: 1000, effect: BalanceEffect::Minus, dateStart: date('Y-m-01')));
    $sum = array_sum(array_map(static fn ($b): float => (float) $b->amount, $spent->bills));
    printf("Spent since the first of this month: %.2f %s in %d operations\n", -$sum, (string) $balance->currency, count($spent));

    exampleHeading('Your own carrier contracts and announced price list changes');
    $agreements = $client->listServiceAgreements();
    echo $agreements === [] ? "No contracts of your own (the account uses Furgonetka's).\n" : count($agreements) . " contract(s) of your own.\n";

    foreach ($agreements as $agreement) {
        echo "  service_id {$agreement->serviceId}: {$agreement->name} ({$agreement->service})\n";
    }

    $changes = $client->listPlannedPricelistChanges();
    echo $changes === [] ? "No price list changes announced.\n" : '';

    foreach ($changes as $change) {
        echo "  {$change->date?->format('Y-m-d')} {$change->service}: {$change->url}\n";
    }
});
