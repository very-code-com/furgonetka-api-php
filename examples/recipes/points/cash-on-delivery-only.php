<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointFilterType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: only those that take cash on delivery',
    shows: 'PointFilterType::CodOnly keeps the points where a COD parcel can be delivered. Without it a COD shipment '
        . 'to a point may be refused later.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost, PointService::Dpd], searchPhrase: 'Warszawa, Marszalkowska 100', pointTypes: [PointFilterType::CodOnly], limit: 5));
        $lines = [count($list->points) . ' points that take COD:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
