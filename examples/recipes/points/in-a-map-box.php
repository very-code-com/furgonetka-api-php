<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Dto\MapBounds;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: inside the visible part of a map',
    shows: 'A map widget asks for the points inside the box it shows: MapBounds::box(north, west, south, east). The '
        . 'search still needs a place (here the centre of the map), as the documentation says.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost], coordinates: new Coordinates(52.2297, 21.0122), mapBounds: MapBounds::box(52.235, 21.005, 52.225, 21.020), limit: 10));
        $lines = [count($list->points) . ' points in the box:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
