<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

return Recipe::query(
    title: 'Account: what came in',
    shows: 'BalanceQuery::effect Plus keeps money that came in: refunds of cancelled shipments and top-ups.',
    query: static function (FurgonetkaApiClient $client): array {
        $in = $client->fetchBalance(new BalanceQuery(items: 5, effect: BalanceEffect::Plus));
        $lines = [count($in) . ' recent operations that added money:'];
        foreach ($in as $bill) {
            $lines[] = sprintf('%s %9.2f  %s', $bill->datetime?->format('Y-m-d H:i') ?? '?', (float) $bill->amount, (string) $bill->typeValue);
        }

        return $lines;
    },
);
