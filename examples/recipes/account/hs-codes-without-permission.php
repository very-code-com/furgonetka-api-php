<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\HsCodeService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: customs tariff codes for a carrier the account may not use',
    shows: 'A search at a carrier the account has no right to ends as an error result, not as an exception, with a '
        . 'message and no code: read isSuccessful() and errors.',
    query: static function (FurgonetkaApiClient $client): array {
        $result = $client->searchHsCodes(HsCodeService::Meest, 'shoes');

        return ['Status: ' . (string) $result->statusValue, 'Errors: ' . implode('; ', array_map('strval', $result->errors))];
    },
);
