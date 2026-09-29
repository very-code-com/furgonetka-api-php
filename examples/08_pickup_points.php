<?php

declare(strict_types=1);

/**
 * Finds pickup points (parcel lockers and shops) and shows how a shipment is addressed to one.
 *
 *   php examples/08_pickup_points.php [phrase]        # default: "Warszawa, Towarowa 20"
 *
 * Needs no login for the search: findPoints() is a public endpoint. Nothing is created.
 *
 * What it shows:
 *   - PointsQuery wants a place (coordinates, a phrase or an address) and at least one carrier;
 *   - a phrase that Furgonetka cannot place does not fail: you get the points around a default centre in
 *     Warsaw, so check the distance and the address before you trust the first result;
 *   - a point is identified by `code` (Point::identifier()), and that is what goes into Receiver::$point;
 *   - the special values `any_apm` (any parcel locker) and `any_parcel_shop` let the carrier choose.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

exampleRun(static function (): void {
    $client = examplePublicClient();

    $phrase = exampleArgument(1) ?? 'Warszawa, Towarowa 20';
    $points = $client->findPoints(new PointsQuery(services: [PointService::Inpost], searchPhrase: $phrase, limit: 5));

    exampleHeading("InPost points near \"{$phrase}\"");
    foreach ($points->points as $point) {
        printf(
            "%-9s %-32s %s %s%s\n",
            (string) $point->identifier(),
            (string) $point->name,
            (string) $point->address?->street,
            (string) $point->address?->city,
            $point->distance !== null ? sprintf('  (%.2f km)', $point->distance) : '',
        );
    }

    $first = $points->first();

    if ($first === null) {
        echo "No point found.\n";

        return;
    }

    exampleHeading('Addressing a shipment to a point');
    $receiver = new Receiver(name: 'Anna Nowak', countryCode: 'PL', email: 'anna@example.com', phone: '600700800', point: $first->identifier());
    echo 'Receiver: ' . json_encode($receiver->toArray(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo "Or let the carrier choose a locker: new Receiver(..., point: 'any_apm').\n";

    $parcel = Parcel::package(30, 40, 20, 5.0, 100.0, 'Books');
    echo 'A locker takes small parcels: ' . json_encode($parcel->toArray()) . PHP_EOL;
});
