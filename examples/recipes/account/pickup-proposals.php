<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: when a courier could come',
    shows: 'fetchPickupProposals() lists the days and time windows per shipment; the hash of a proposal goes back '
        . 'with orderPickup(). Reading them books nothing. It refuses a shipment that is not ordered (a cancelled '
        . 'one is answered with sent, "the package has already been ordered"), so the recipe takes the newest one '
        . 'that is.',
    query: static function (FurgonetkaApiClient $client): array {
        $package = Scenario::latestOrdered($client);
        $lines = ['Shipment ' . $package->id . ':'];
        foreach ($client->fetchPickupProposals([(string) $package->id]) as $group) {
            foreach ($group->available() as $proposal) {
                $lines[] = sprintf('%s %s-%s %s', (string) $proposal->date, (string) $proposal->minTime, (string) $proposal->maxTime, (string) $proposal->info);
            }
        }

        return $lines;
    },
);
