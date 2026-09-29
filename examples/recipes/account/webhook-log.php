<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the webhook log',
    shows: 'listWebhookLogs() lists the deliveries that failed, with what your receiver answered; a successful '
        . 'delivery leaves no entry and only moves lastSuccess. resendWebhook() sends one again.',
    query: static function (FurgonetkaApiClient $client): array {
        $page = $client->listWebhookLogs();
        $lines = [count($page) . ' failed deliveries; last success: ' . ($page->lastSuccess?->format('Y-m-d H:i') ?? 'never')];
        foreach ($page as $log) {
            $lines[] = sprintf('#%s %s HTTP %s %s', (string) $log->id, $log->datetimeSend?->format('Y-m-d H:i') ?? '?', (string) $log->httpCode, (string) $log->webhookResponse);
        }

        return $lines;
    },
);
