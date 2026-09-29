<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: how the size changes the price',
    shows: 'The same 2 kg parcel in three sizes: the price follows the size as well as the weight.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $lines = [];
        foreach ([[20, 15, 10], [40, 30, 20], [80, 60, 40]] as [$w, $d, $h]) {
            $request = $s->request(parcels: [Parcel::package($w, $d, $h, 2.0, 100.0, 'Goods')]);
            $quote = $client->calculatePrices($request, PriceScope::serviceIds($request->serviceId))->forServiceId($request->serviceId);
            $lines[] = sprintf('%d x %d x %d cm  %s', $w, $d, $h, $quote !== null && $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available');
        }

        return $lines;
    },
);
