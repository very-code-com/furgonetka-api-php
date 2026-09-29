<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: what a higher declared value costs',
    shows: 'The same parcel with a declared value of 100, 1000 and 5000 PLN, to see whether the price follows it.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $lines = [];
        foreach ([100.0, 1000.0, 5000.0] as $value) {
            $request = $s->request(parcels: [Parcel::package(30, 40, 20, 5.0, $value, 'Goods')]);
            $quote = $client->calculatePrices($request, PriceScope::serviceIds($request->serviceId))->forServiceId($request->serviceId);
            $lines[] = sprintf('%7.0f PLN  %s', $value, $quote !== null && $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available');
        }

        return $lines;
    },
);
