<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: paging',
    shows: 'A page ends where the next begins: PackagePage::nextLastPackageId() is the value for lastPackageId of '
        . 'the next query. There is no page number.',
    query: static function (FurgonetkaApiClient $client): array {
        $first = $client->listPackages(new PackageListQuery(limit: 3, listType: ListType::Ordered));
        $lines = ['Page 1:'];
        foreach ($first->packages as $package) {
            $lines[] = Scenario::packageLine($package);
        }

        $next = $first->nextLastPackageId();
        $lines[] = 'Page 2 starts after ' . ($next ?? 'nothing') . ':';
        if ($next !== null) {
            foreach ($client->listPackages(new PackageListQuery(limit: 3, lastPackageId: $next, listType: ListType::Ordered))->packages as $package) {
                $lines[] = Scenario::packageLine($package);
            }
        }

        return $lines;
    },
);
