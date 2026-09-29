<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: by contract instead of by carrier',
    shows: 'PriceScope::serviceIds() prices exactly the contracts you name, which matters when you have your own '
        . 'contract next to Furgonetka\'s.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $ids = [];
        foreach ([Carrier::Dpd, Carrier::InPost] as $carrier) {
            $ids[] = (new Scenario($client, $carrier))->serviceId();
        }

        $quotes = $client->calculatePrices($s->request(), PriceScope::serviceIds(...$ids));
        $lines = [];
        foreach ($quotes->quotes as $quote) {
            $lines[] = sprintf('service %-9s %-10s %s', (string) $quote->serviceId, (string) $quote->carrierName, $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available');
        }

        return $lines;
    },
);
