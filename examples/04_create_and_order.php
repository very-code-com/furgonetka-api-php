<?php

declare(strict_types=1);

/**
 * From a shipment to a label: validate, price, create, check the regulations, order, download the label.
 *
 *   php examples/04_create_and_order.php            # dry: validates and prices, orders nothing
 *   php examples/04_create_and_order.php --order    # creates, orders and downloads the label
 *   php examples/04_create_and_order.php --order --accept-regulations
 *
 * Ordering is real: on production it charges the account and books a carrier. So this script is dry
 * unless you give --order, and it refuses --order unless FURGONETKA_ENV=sandbox or you also give
 * --i-mean-production. Replace the addresses in bootstrap.php's exampleShipment() before you do.
 *
 * What it shows:
 *   - validateLocally() and warningsFor() need no request; validateRemotely() asks Furgonetka and is the
 *     authority (an empty list means valid);
 *   - a carrier needs its regulations accepted before the first order (a 409 otherwise). The library never
 *     accepts them by itself: pendingRegulations() lists what stands in the way, and accepting is your
 *     explicit act, here the --accept-regulations flag;
 *   - orderPackages() waits for the command. A carrier's rejection is a result (`error` or
 *     `partial_success`), not an exception: read isSuccessful() and errorsFor();
 *   - the order is submitted under a UUID that you may choose. If the connection drops, repeat the call
 *     with the same UUID and it reads the result instead of ordering twice.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;

exampleRun(static function (): void {
    $client = exampleClient();
    $order = exampleFlag('--order');

    if ($order && exampleConfig()->getEnvironment() !== 'sandbox' && !exampleFlag('--i-mean-production')) {
        throw new \RuntimeException('Refusing to order on production. Use FURGONETKA_ENV=sandbox, or add --i-mean-production if you really mean it.');
    }

    $request = exampleShipment($client, Carrier::Dpd, 'Example order');

    exampleHeading('1. Check the shipment');
    $local = $client->validateLocally($request, Carrier::Dpd);
    echo $local === [] ? "Local rules: fine.\n" : 'Local rules: ' . implode('; ', $local) . PHP_EOL;

    foreach ($client->warningsFor($request, Carrier::Dpd) as $warning) {
        echo "Warning: {$warning}\n";
    }

    $remote = $client->validateRemotely($request);
    echo $remote === [] ? "Furgonetka: fine.\n" : 'Furgonetka refuses: ' . implode('; ', array_map('strval', $remote)) . PHP_EOL;

    if ($remote !== []) {
        return;
    }

    exampleHeading('2. Price');
    $quote = $client->calculatePrices($request, PriceScope::carriers(Carrier::Dpd))->cheapest();
    echo $quote === null ? "No price: the carrier cannot take this shipment.\n" : sprintf("%s: %.2f PLN gross\n", $quote->carrierName ?? '?', (float) $quote->pricing?->priceGross);

    if (!$order) {
        echo "\nDry run: nothing was created. Add --order to create and order it.\n";

        return;
    }

    exampleHeading('3. Create (a waiting shipment, not yet ordered)');
    $package = $client->createPackage($request);
    echo "Created shipment {$package->id}\n";

    exampleHeading('4. Regulations');
    $pending = $client->pendingRegulations([(string) $package->id]);

    if ($pending !== [] && !exampleFlag('--accept-regulations')) {
        foreach ($pending as $regulation) {
            echo "Not accepted yet: {$regulation->service} - {$regulation->name}\n";
        }

        echo "Read them, then run again with --accept-regulations to accept them on this account.\n";
        $client->deletePackage($package);
        echo "The waiting shipment was deleted.\n";

        return;
    }

    if ($pending !== []) {
        $client->acceptRegulations($pending);
        echo 'Accepted ' . count($pending) . " regulation(s).\n";
    } else {
        echo "Nothing to accept.\n";
    }

    exampleHeading('5. Order');
    $uuid = Uuid::v4();
    echo "Order UUID {$uuid} (repeat the call with it after a network error)\n";

    try {
        $result = $client->orderPackages([(string) $package->id], uuid: $uuid);
    } catch (FurgonetkaApiConflictException $e) {
        echo "The account still has regulations to accept: {$e->getMessage()}\n";

        return;
    }

    if (!$result->isSuccessful()) {
        foreach ($result->errors as $error) {
            echo "Not ordered: {$error}\n";
        }

        return;
    }

    echo 'Ordered: ' . implode(', ', $result->successfulPackageIds()) . PHP_EOL;

    exampleHeading('6. Label');
    $ordered = $client->fetchPackage($package);
    echo 'Waybill number(s): ' . implode(', ', $ordered->waybillNumbers()) . PHP_EOL;

    $label = $client->fetchLabel($package);
    $file = sys_get_temp_dir() . '/furgonetka-label-' . $package->id . '.pdf';
    file_put_contents($file, $label->bytes);
    echo "Label ({$label->size()} bytes) saved to {$file}\n";
    echo "Cancel it with: php examples/05_courier_pickup.php --cancel {$package->id}\n";
});
