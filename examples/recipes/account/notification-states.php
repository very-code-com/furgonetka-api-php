<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: what is sent at each tracking state',
    shows: 'fetchShippingNotifications() has a group per tracking state, with the channels for ordered shipments '
        . '(SMS, e-mail, push, webhook) and for tracked ones (push, webhook).',
    query: static function (FurgonetkaApiClient $client): array {
        $lines = [];
        foreach ($client->fetchShippingNotifications() as $group) {
            $on = array_keys(array_filter(['sms' => $group->order->sms, 'email' => $group->order->email, 'push' => $group->order->push, 'webhook' => $group->order->webhook]));
            $lines[] = sprintf('%-22s %s', (string) $group->key, $on === [] ? '-' : implode(', ', $on));
        }

        return $lines;
    },
);
