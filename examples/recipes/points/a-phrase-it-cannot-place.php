<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: a phrase that cannot be placed does not fail',
    shows: 'A phrase Furgonetka cannot place is not an error and not an empty list: you get the points around a '
        . 'default centre in Warsaw. Check the address of the first result before you trust it.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost], searchPhrase: 'qwertyuiop 12345', limit: 3));
        $lines = [count($list->points) . ' points for a phrase that means nothing:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point);
        }

        return $lines;
    },
);
