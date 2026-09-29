<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

return Recipe::query(
    title: 'Account: what was spent this month',
    shows: 'BalanceQuery::effect Minus keeps money that went out, and dateStart is a day. The sum is a loop over the '
        . 'operations.',
    query: static function (FurgonetkaApiClient $client): array {
        $spent = $client->fetchBalance(new BalanceQuery(items: 1000, effect: BalanceEffect::Minus, dateStart: date('Y-m-01')));
        $sum = array_sum(array_map(static fn ($bill): float => (float) $bill->amount, $spent->bills));

        return [sprintf('%.2f %s spent in %d operations since %s', -$sum, (string) $spent->currency, count($spent), date('Y-m-01'))];
    },
);
