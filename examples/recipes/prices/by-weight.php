<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: how the weight changes the price',
    shows: 'The same route priced for parcels of 1, 5, 10, 20 and 30 kg with one carrier: the tariff steps show.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $lines = [];
        foreach ([1.0, 5.0, 10.0, 20.0, 30.0] as $kg) {
            $request = $s->request(parcels: [Parcel::package(40, 30, 20, $kg, 100.0, 'Goods')]);
            $quote = $client->calculatePrices($request, PriceScope::serviceIds($request->serviceId))->forServiceId($request->serviceId);
            $lines[] = sprintf('%5.1f kg  %s', $kg, $quote !== null && $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available');
        }

        return $lines;
    },
);
