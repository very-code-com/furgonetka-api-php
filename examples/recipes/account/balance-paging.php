<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

return Recipe::query(
    title: 'Account: paging through the operations',
    shows: 'lastId continues below the id of the last operation of a page, and is steadier than a page number while '
        . 'new operations arrive.',
    query: static function (FurgonetkaApiClient $client): array {
        $first = $client->fetchBalance(new BalanceQuery(items: 3));
        $lines = ['First page: ' . implode(', ', array_map(static fn ($bill): string => (string) $bill->id, $first->bills))];
        $second = $client->fetchBalance(new BalanceQuery(items: 3, lastId: $first->nextLastId()));
        $lines[] = 'After ' . (string) $first->nextLastId() . ': ' . implode(', ', array_map(static fn ($bill): string => (string) $bill->id, $second->bills));

        return $lines;
    },
);
