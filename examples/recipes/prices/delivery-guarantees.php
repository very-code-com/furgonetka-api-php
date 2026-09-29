<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: what a delivery guarantee costs',
    shows: 'The same DPD shipment plain, guaranteed by 12:00 and guaranteed next day.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $variants = ['plain' => null, 'by 12:00' => new AdditionalServices(guarantee1200: true), 'next day' => new AdditionalServices(guaranteeNextDay: true)];
        $lines = [];
        foreach ($variants as $label => $services) {
            $request = $s->request(services: $services);
            $quote = $client->calculatePrices($request, PriceScope::serviceIds($request->serviceId))->forServiceId($request->serviceId);
            $lines[] = sprintf('%-10s %s', $label, $quote !== null && $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available');
        }

        return $lines;
    },
);
