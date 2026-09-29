<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: Poczta Polska',
    shows: 'The points of Poczta Polska include the shops of the Zabka network; they take COD.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Poczta], searchPhrase: 'Warszawa, Marszalkowska 100', limit: 5));
        $lines = [count($list->points) . ' Poczta points:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
