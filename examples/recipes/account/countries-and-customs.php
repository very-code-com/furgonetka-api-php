<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: countries and which ones need customs data',
    shows: 'fetchAllowedCountries() lists where a shipment can go, and flags the countries that need a customs '
        . 'declaration (duty). requiresDuty() asks for one country.',
    query: static function (FurgonetkaApiClient $client): array {
        $countries = $client->fetchAllowedCountries();

        return [
            'Countries a shipment can go to: ' . count($countries->delivery),
            'Countries that need customs data: ' . implode(', ', $countries->dutyCountryCodes()),
            'Germany needs customs data: ' . var_export($countries->requiresDuty('DE'), true),
            'United Kingdom needs customs data: ' . var_export($countries->requiresDuty('GB'), true),
        ];
    },
);
