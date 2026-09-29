<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: why a carrier cannot take a shipment',
    shows: 'A carrier that cannot take the shipment answers with its own reasons. Here a 60 x 40 x 40 cm, 40 kg '
        . 'parcel is priced with several carriers.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $request = $s->request(parcels: [Parcel::package(60, 40, 40, 40.0, 100.0, 'Anvil')]);
        $quotes = $client->calculatePrices($request, PriceScope::carriers(Carrier::Dpd, Carrier::InPost, Carrier::Gls, Carrier::Fedex, Carrier::Ups, Carrier::Poczta));
        $lines = [];
        foreach ($quotes->quotes as $quote) {
            $lines[] = sprintf('%-10s %s', (string) $quote->carrierName, $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available: ' . mb_substr(implode('; ', array_map('strval', $quote->errors)), 0, 90));
        }

        return $lines;
    },
);
