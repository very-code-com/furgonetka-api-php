<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\BillGroup;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

return Recipe::query(
    title: 'Account: only one group of operations',
    shows: 'BalanceQuery::group narrows to courier services or to the commissions of the freight exchange.',
    query: static function (FurgonetkaApiClient $client): array {
        $lines = [];
        foreach ([BillGroup::CourierServices, BillGroup::HaulerCommission] as $group) {
            $balance = $client->fetchBalance(new BalanceQuery(items: 3, group: $group));
            $lines[] = $group->value . ': ' . count($balance) . ' operations on the page';
        }

        return $lines;
    },
);
