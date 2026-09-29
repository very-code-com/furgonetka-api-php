<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: how many in each state',
    shows: 'PackageState says where a shipment stands in Furgonetka (waiting, ordered, cancelled, tracking); a list '
        . 'of all of them, counted.',
    query: static function (FurgonetkaApiClient $client): array {
        $page = $client->listPackages(new PackageListQuery(limit: 50, listType: ListType::All));
        $counts = [];
        foreach ($page->packages as $package) {
            $name = (string) ($package->state->value ?? $package->stateValue);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $lines = [count($page->packages) . ' shipments on the page:'];
        foreach ($counts as $name => $count) {
            $lines[] = sprintf('%-12s %d', $name, $count);
        }

        return $lines;
    },
);
