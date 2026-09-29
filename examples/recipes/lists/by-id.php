<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageFilters;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: by id',
    shows: 'PackageFilters::packagesIds fetches specific shipments in one call, for instance the ones your own '
        . 'database points at.',
    query: static function (FurgonetkaApiClient $client): array {
        $ids = array_map(static fn ($package): string => (string) $package->id, $client->listPackages(new PackageListQuery(limit: 2, listType: ListType::Ordered))->packages);
        $page = $client->listPackages(new PackageListQuery(limit: 10, listType: ListType::Ordered, filters: new PackageFilters(packagesIds: $ids)));
        $lines = ['Asked for ' . implode(', ', $ids) . ', got ' . count($page->packages)];
        foreach ($page->packages as $package) {
            $lines[] = Scenario::packageLine($package);
        }

        return $lines;
    },
);
