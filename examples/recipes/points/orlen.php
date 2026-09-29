<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: ORLEN Paczka',
    shows: 'ORLEN points are stations and lockers (APM). Their address comes without a postcode, which matters when '
        . 'the sender leaves a parcel at one.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Orlen], searchPhrase: 'Warszawa, Marszalkowska 100', limit: 5));
        $lines = [count($list->points) . ' ORLEN points:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point) . '  postcode: ' . ($point->address?->postcode ?: 'not given');
        }

        return $lines;
    },
);
