<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: every carrier for one shipment',
    shows: 'One request prices several contracts. A carrier that cannot take the shipment is not an error: it has '
        . 'available false and its own errors. Sort what is available by price.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $quotes = $client->calculatePrices($s->request(), PriceScope::carriers(Carrier::Dpd, Carrier::InPost, Carrier::Gls, Carrier::Fedex, Carrier::Ups, Carrier::Poczta, Carrier::Dhl));
        $available = $quotes->available();
        usort($available, static fn ($a, $b): int => ($a->pricing->priceGross ?? 0) <=> ($b->pricing->priceGross ?? 0));
        $lines = [];
        foreach ($available as $quote) {
            $lines[] = sprintf('%-10s %8.2f PLN gross', (string) $quote->carrierName, (float) $quote->pricing?->priceGross);
        }

        return $lines;
    },
);
