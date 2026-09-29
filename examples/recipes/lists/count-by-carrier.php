<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

return Recipe::query(
    title: 'Shipments: how many per carrier',
    shows: 'A summary is a loop over a big page: fifty ordered shipments, counted by carrier.',
    query: static function (FurgonetkaApiClient $client): array {
        $page = $client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Ordered));
        $counts = [];
        foreach ($page->packages as $package) {
            $name = (string) ($package->carrier->value ?? $package->carrierName);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        arsort($counts);
        $lines = [count($page->packages) . ' ordered shipments on the page:'];
        foreach ($counts as $name => $count) {
            $lines[] = sprintf('%-14s %d', $name, $count);
        }

        return $lines;
    },
);
