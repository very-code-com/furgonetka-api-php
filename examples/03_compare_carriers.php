<?php

declare(strict_types=1);

/**
 * Prices one shipment with several carriers at once and picks the cheapest that can take it.
 *
 *   php examples/03_compare_carriers.php
 *
 * Nothing is created: calculatePrices() only asks.
 *
 * What it shows:
 *   - a price request is not an error path: a carrier that cannot take the parcel (size, address) comes
 *     back with `available` false and its own errors, so read the result per carrier;
 *   - cheapest() skips the unavailable ones;
 *   - PriceScope narrows the request to some carriers or contracts.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;

exampleRun(static function (): void {
    $client = exampleClient();

    // Any contract of the account can be the base of the request; the scope decides who is priced.
    $request = exampleShipment($client, Carrier::Dpd);

    $quotes = $client->calculatePrices($request, PriceScope::carriers(Carrier::Dpd, Carrier::InPost, Carrier::Gls, Carrier::Fedex, Carrier::Ups, Carrier::Poczta));

    exampleHeading('Every carrier asked');
    foreach ($quotes->quotes as $quote) {
        $name = $quote->carrierName ?? '?';

        if (!$quote->available) {
            $why = $quote->errors !== [] ? (string) $quote->errors[0] : 'not available';
            printf("%-14s service %-9s not available: %s\n", $name, (string) $quote->serviceId, $why);

            continue;
        }

        printf("%-14s service %-9s %8.2f PLN gross  %s\n", $name, (string) $quote->serviceId, (float) $quote->pricing?->priceGross, $quote->agreementOwnerValue ?? '');
    }

    $cheapest = $quotes->cheapest();

    exampleHeading('The cheapest');
    echo $cheapest === null
        ? "No carrier can take this shipment.\n"
        : sprintf("%s (service %d) at %.2f PLN gross\n", $cheapest->carrierName ?? '?', (int) $cheapest->serviceId, (float) $cheapest->pricing?->priceGross);
});
