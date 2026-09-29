<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: only staffed points',
    shows: 'PointServiceType::ServicePoint keeps shops and kiosks, where a person hands the parcel over.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost, PointService::Dpd], searchPhrase: 'Warszawa, Marszalkowska 100', type: PointServiceType::ServicePoint, limit: 5));
        $lines = [count($list->points) . ' staffed points:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
