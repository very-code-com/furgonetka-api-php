<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: around a pair of coordinates',
    shows: 'A map application already knows where the customer is: give the coordinates instead of a phrase. The '
        . 'answer repeats the centre of the search as numbers.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost], coordinates: new Coordinates(52.2297, 21.0122), limit: 5));
        $lines = ['Centre ' . ($list->coordinates->latitude ?? '?') . ', ' . ($list->coordinates->longitude ?? '?')];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
