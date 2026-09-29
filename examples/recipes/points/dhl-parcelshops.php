<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: DHL ParcelShops',
    shows: 'DHL points are ParcelShops and they take COD; a DHL shipment is addressed to one with Receiver::point.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Dhl], searchPhrase: 'Warszawa, Marszalkowska 100', limit: 5));
        $lines = [count($list->points) . ' DHL points:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
