<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: several carriers in one search',
    shows: 'A checkout that offers InPost, DPD, ORLEN and Poczta points asks once and sorts the answer by carrier '
        . 'with PointList::forService().',
    query: static function (FurgonetkaApiClient $client): array {
        $carriers = [PointService::Inpost, PointService::Dpd, PointService::Orlen, PointService::Poczta];
        $list = $client->findPoints(new PointsQuery(services: $carriers, searchPhrase: 'Warszawa, Marszalkowska 100', limit: 40));
        $lines = [count($list->points) . ' points in all'];
        foreach ($carriers as $service) {
            $lines[] = sprintf('%-8s %d', $service->value, count($list->forService($service)));
        }

        return $lines;
    },
);
