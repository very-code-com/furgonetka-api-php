<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the regulations and consents',
    shows: 'listRegulations() returns every regulation and consent of the account, about forty, accepted or not: '
        . 'carrier terms, marketing, push. A summary is more useful than the list; acceptRegulations() is never '
        . 'called for you.',
    query: static function (FurgonetkaApiClient $client): array {
        $accepted = 0;
        $open = [];
        foreach ($client->listRegulations() as $regulation) {
            if ($regulation->accepted === true) {
                ++$accepted;
            } else {
                $open[] = (string) ($regulation->service ?? $regulation->name);
            }
        }

        return ['Accepted: ' . $accepted, 'Not accepted: ' . count($open), 'Some of them: ' . implode(', ', array_slice(array_unique($open), 0, 8))];
    },
);
