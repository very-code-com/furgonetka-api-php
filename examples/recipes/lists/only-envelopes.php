<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageFilters;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: only envelopes',
    shows: 'PackageFilters::packageTypes keeps shipments by the kind of parcel: package, dox, pallet or letterprint.',
    query: static function (FurgonetkaApiClient $client): array {
        $page = $client->listPackages(new PackageListQuery(limit: 5, listType: ListType::Ordered, filters: new PackageFilters(packageTypes: [ParcelType::Dox])));
        $lines = [count($page->packages) . ' shipments' . ($page->count !== null ? ' (' . $page->count . ' in all)' : '') . ':'];
        foreach ($page->packages as $package) {
            $lines[] = Scenario::packageLine($package);
        }

        return $lines;
    },
);
