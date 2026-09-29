<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

return Recipe::query(
    title: 'Account: the latest operations',
    shows: 'fetchBalance() lists operations newest first with the balance. items is the page size, from 1 to 1000; '
        . 'more would silently be read as 10, so the library refuses it.',
    query: static function (FurgonetkaApiClient $client): array {
        $balance = $client->fetchBalance(new BalanceQuery(items: 5));
        $lines = [sprintf('Balance %.2f %s, %d operations in all', (float) $balance->balance, (string) $balance->currency, (int) $balance->billsCount)];
        foreach ($balance as $bill) {
            $lines[] = sprintf('%s %9.2f  %-16s %s', $bill->datetime?->format('Y-m-d H:i') ?? '?', (float) $bill->amount, (string) $bill->typeValue, (string) ($bill->descriptionFormatted ?? $bill->description));
        }

        return $lines;
    },
);
