<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the webhook',
    shows: 'fetchWebhookSettings() says whether a webhook is set up and in which format. The token is not shown in '
        . 'dumps.',
    query: static function (FurgonetkaApiClient $client): array {
        $webhook = $client->fetchWebhookSettings();

        return [$webhook->isConfigured() ? 'Set: ' . $webhook->url . ' (' . $webhook->formatValue . ')' : 'No webhook is set up.'];
    },
);
