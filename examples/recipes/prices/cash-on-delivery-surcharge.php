<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: what cash on delivery adds',
    shows: 'The same shipment priced with and without a COD of 100 PLN, per carrier. The difference is what the COD '
        . 'costs.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $scope = PriceScope::carriers(Carrier::Dpd, Carrier::InPost, Carrier::Fedex, Carrier::Poczta);
        $plain = $client->calculatePrices($s->request(), $scope);
        $withCod = $client->calculatePrices($s->request(services: new AdditionalServices(cod: Scenario::cashOnDelivery(100.0))), $scope);
        $lines = [];
        foreach ($plain->available() as $quote) {
            $other = $withCod->forServiceId((int) $quote->serviceId);
            $lines[] = sprintf('%-10s %7.2f -> %7.2f (+%.2f)', (string) $quote->carrierName, (float) $quote->pricing?->priceGross, (float) $other?->pricing?->priceGross, (float) $other?->pricing?->priceGross - (float) $quote->pricing?->priceGross);
        }

        return $lines;
    },
);
