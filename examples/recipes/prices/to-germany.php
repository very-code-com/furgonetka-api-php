<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\Examples\Scenario;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Prices: a parcel to Germany',
    shows: 'The same parcel to Berlin priced with the carriers that serve it.',
    query: static function (FurgonetkaApiClient $client): array {
        $s = new Scenario($client, Carrier::Dpd);
        $receiver = new Receiver(name: 'Hans Mueller', street: 'Unter den Linden 1', postcode: '10117', city: 'Berlin', countryCode: 'DE', email: 'hans@example.com', phone: '+491701234567');
        $quotes = $client->calculatePrices($s->request(receiver: $receiver), PriceScope::carriers(Carrier::Dpd, Carrier::Gls, Carrier::Fedex, Carrier::Ups, Carrier::Poczta));
        $lines = [];
        foreach ($quotes->quotes as $quote) {
            $lines[] = sprintf('%-10s %s', (string) $quote->carrierName, $quote->available ? sprintf('%.2f PLN gross', (float) $quote->pricing?->priceGross) : 'not available: ' . implode('; ', array_map('strval', $quote->errors)));
        }

        return $lines;
    },
);
