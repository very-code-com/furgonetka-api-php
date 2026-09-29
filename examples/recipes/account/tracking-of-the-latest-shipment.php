<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the tracking of the newest shipment',
    shows: 'fetchTracking() answers per waybill number; an event has a normalised state to branch on and the '
        . 'carrier\'s own wording, which is free text.',
    query: static function (FurgonetkaApiClient $client): array {
        $package = Scenario::latestOrdered($client);
        $tracking = $client->fetchTracking($package);
        $lines = ['Shipment ' . $package->id . ', waybill numbers: ' . implode(', ', $tracking->numbers())];
        foreach ($tracking->numbers() as $number) {
            $parcel = $tracking->parcel($number);
            $lines[] = $number . ': ' . ($parcel?->state()->value ?? 'no state yet') . ($parcel?->isDelivered() === true ? ' (delivered)' : '') . ', ' . count($parcel->events ?? []) . ' events';
        }

        return $lines;
    },
);
