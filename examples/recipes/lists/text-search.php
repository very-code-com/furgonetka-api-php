<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: a search by text',
    shows: 'PackageListQuery::query searches by a waybill number, a reference or a name.',
    query: static function (FurgonetkaApiClient $client): array {
        $waybill = $client->listPackages(new PackageListQuery(limit: 1, listType: ListType::Ordered))->packages[0]->waybillNumbers()[0] ?? throw new \RuntimeException('No ordered shipment with a waybill number.');
        $page = $client->listPackages(new PackageListQuery(limit: 10, listType: ListType::Ordered, query: $waybill));
        $lines = ['Searching for ' . $waybill . ' found ' . count($page->packages)];
        foreach ($page->packages as $package) {
            $lines[] = Scenario::packageLine($package);
        }

        return $lines;
    },
);
