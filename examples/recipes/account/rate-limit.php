<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the request quota',
    shows: 'Every answer carries the limit and what remains; lastRateLimit() reads it after any call. The limit '
        . 'differs per endpoint, and a 429 is FurgonetkaApiRateLimitException.',
    query: static function (FurgonetkaApiClient $client): array {
        $client->listServices();
        $limit = $client->lastRateLimit();

        return $limit === null ? ['No quota was reported.'] : [sprintf('%d of %d requests left; low: %s; exhausted: %s', $limit->remaining, $limit->limit, var_export($limit->isLow(), true), var_export($limit->isExhausted(), true))];
    },
);
