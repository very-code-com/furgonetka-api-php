<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Enum\HsCodeService;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: customs tariff codes for Nova Post',
    shows: 'searchHsCodes() looks up HS codes for a description. It is a command that needs the token, and codes() '
        . 'lists each code once although the answer repeats it with several descriptions.',
    query: static function (FurgonetkaApiClient $client): array {
        $result = $client->searchHsCodes(HsCodeService::NovaPost, 'shoes');

        return [$result->isSuccessful() ? count($result->hsCodes) . ' entries, ' . count($result->codes()) . ' different codes, for instance ' . implode(', ', array_slice($result->codes(), 0, 4)) : 'No codes: ' . implode('; ', array_map('strval', $result->errors))];
    },
);
