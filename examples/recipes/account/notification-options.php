<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the e-mail notification options',
    shows: 'fetchGeneralNotifications() lists the account-wide e-mail options. An option the account never set reads '
        . 'as null, not as false.',
    query: static function (FurgonetkaApiClient $client): array {
        $lines = [];
        foreach ($client->fetchGeneralNotifications() as $option) {
            $lines[] = sprintf('%-30s %s', (string) $option->key, $option->enabled === null ? 'not set' : ($option->enabled ? 'on' : 'off'));
        }

        return $lines;
    },
);
