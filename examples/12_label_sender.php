<?php

declare(strict_types=1);

/**
 * "Other data on the label": ship as one company, print another one on the label.
 *
 *   php examples/12_label_sender.php
 *
 * It only validates and prices; it creates nothing. (Add --create to also create a waiting shipment, which the
 * script then deletes again.)
 *
 * What it shows:
 *   - `pickup` is where the courier collects; `sender` (LabelSender) is what is printed on the label. A shop
 *     that dropships puts its own name there and the warehouse address in `pickup`;
 *   - RETURNS GO TO THE ADDRESS ON THE LABEL, not to the pickup address, so the library warns whenever the two
 *     differ (warningsFor());
 *   - the label data need a name or a company; the API also refuses a Polish `county` such as "mazowieckie"
 *     (`invalidRegion`), so leave it out for Poland;
 *   - not every carrier takes them: the documentation lists DPD, DHL, FedEx, UPS, InPost and AmbroExpress, and the
 *     API refuses GLS. It needs no account option (checked on the sandbox);
 *   - a shipment read back shows them in Package::$labelSender, and null when none were given.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;

exampleRun(static function (): void {
    $client = exampleClient();
    $base = exampleShipment($client, Carrier::Dpd, 'Label sender example');

    $request = new ShipmentRequest(
        serviceId: $base->serviceId,
        pickup: $base->pickup,
        receiver: $base->receiver,
        parcels: $base->parcels,
        userReferenceNumber: $base->userReferenceNumber,
        labelSender: new LabelSender('Prosta 1', '00-838', 'Warszawa', name: 'Shop Team', company: 'Example Shop', countryCode: 'PL', email: 'shop@example.com', phone: '500100200'),
    );

    exampleHeading('Local check');
    echo $client->validateLocally($request, Carrier::Dpd) === [] ? "Local rules: fine.\n" : 'Local rules: ' . implode('; ', $client->validateLocally($request, Carrier::Dpd)) . PHP_EOL;

    foreach ($client->warningsFor($request, Carrier::Dpd) as $warning) {
        echo "Warning: {$warning}\n";
    }

    exampleHeading('Furgonetka');
    $errors = $client->validateRemotely($request);
    echo $errors === [] ? "Furgonetka accepts it.\n" : 'Furgonetka refuses: ' . implode('; ', array_map('strval', $errors)) . PHP_EOL;

    if ($errors !== [] || !exampleFlag('--create')) {
        echo "\nNothing was created. Add --create to create a waiting shipment and read the label data back.\n";

        return;
    }

    $package = $client->createPackage($request);

    try {
        $read = $client->fetchPackage($package);
        echo 'On the label: ' . ($read->labelSender->company ?? '-') . ', ' . ($read->labelSender->street ?? '-') . PHP_EOL;
        echo 'The pickup stays: ' . ($read->pickup->name ?? '-') . ', ' . ($read->pickup->street ?? '-') . PHP_EOL;
    } finally {
        $client->deletePackage($package);
        echo "The waiting shipment was deleted.\n";
    }
});
