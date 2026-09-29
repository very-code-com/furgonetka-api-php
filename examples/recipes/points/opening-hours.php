<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

return Recipe::query(
    title: 'Points: opening hours',
    shows: 'Point::openingHours has one entry per weekday, with the start and end time; a locker is open around the '
        . 'clock, a shop is not.',
    query: static function (FurgonetkaApiClient $client): array {
        $point = $client->findPoints(new PointsQuery(services: [PointService::Poczta], searchPhrase: 'Warszawa, Chmielna 35', limit: 1))->first();
        $lines = [Scenario::pointLine($point ?? throw new \RuntimeException('No point found.'))];
        foreach ($point->openingHours?->days() ?? [] as $day => $hours) {
            $lines[] = sprintf('%-10s %s - %s', (string) $day, $hours->start ?? 'closed', $hours->end ?? '');
        }

        return $lines;
    },
);
