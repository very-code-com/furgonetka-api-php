<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: one point by its code',
    shows: 'PointsQuery::forPoint() reads one point again by the code you stored, for instance to check that it is '
        . 'still active before you print a label.',
    query: static function (FurgonetkaApiClient $client): array {
        $code = $client->findPoints(new PointsQuery(services: [PointService::Inpost], searchPhrase: 'Warszawa, Towarowa 20', limit: 1))->first()?->identifier() ?? throw new \RuntimeException('No point found.');
        $point = $client->findPoints(PointsQuery::forPoint($code, [PointService::Inpost]))->first();

        return [
            'Asked for ' . $code . ', got ' . ($point?->identifier() ?? 'nothing'),
            'Active: ' . var_export($point?->active, true) . ', takes COD: ' . var_export($point?->cod, true) . ', maximum weight: ' . ($point->maxSupportedWeight ?? 'not given'),
            'Address: ' . ($point->address->street ?? '?') . ', ' . ($point->address->postcode ?? '?') . ' ' . ($point->address->city ?? '?'),
        ];
    },
);
