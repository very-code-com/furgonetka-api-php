<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\PointAddress;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: around a structured address',
    shows: 'PointAddress splits the address into street, postcode, city and country, which is what a checkout form '
        . 'has.',
    query: static function (FurgonetkaApiClient $client): array {
        $address = new PointAddress(postcode: '00-026', street: 'Marszalkowska 100', city: 'Warszawa', countryCode: 'PL');
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost], address: $address, limit: 5));
        $lines = [count($list->points) . ' points near ' . $address->street . ':'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
