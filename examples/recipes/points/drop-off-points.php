<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointFilterType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: where the sender can leave a parcel',
    shows: 'PointFilterType::SendPoints keeps the points that accept parcels from a sender (a delivery-only point '
        . 'would not); DeliveryPoints is the opposite filter.',
    query: static function (FurgonetkaApiClient $client): array {
        $list = $client->findPoints(new PointsQuery(services: [PointService::Inpost, PointService::Dpd], searchPhrase: 'Warszawa, Marszalkowska 100', pointTypes: [PointFilterType::SendPoints], limit: 5));
        $lines = [count($list->points) . ' points to drop a parcel at:'];
        foreach ($list->points as $point) {
            $lines[] = Scenario::pointLine($point) . ($point->isSendPoint === true ? '  (send point)' : '');
        }

        return $lines;
    },
);
