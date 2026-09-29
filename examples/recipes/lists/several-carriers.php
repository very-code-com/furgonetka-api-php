<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageFilters;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: several carriers',
    shows: 'Give more than one carrier to see them together: PackageFilters::services takes carriers or their '
        . 'identifiers as text.',
    query: static function (FurgonetkaApiClient $client): array {
        $page = $client->listPackages(new PackageListQuery(limit: 5, listType: ListType::Ordered, filters: new PackageFilters(services: [Carrier::InPost, Carrier::Gls])));
        $lines = [count($page->packages) . ' shipments' . ($page->count !== null ? ' (' . $page->count . ' in all)' : '') . ':'];
        foreach ($page->packages as $package) {
            $lines[] = Scenario::packageLine($package);
        }

        return $lines;
    },
);
