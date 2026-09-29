<?php

declare(strict_types=1);

/**
 * Books a courier to collect ordered shipments, and cancels shipments.
 *
 *   php examples/05_courier_pickup.php <package-id>             # dry: shows the proposed dates
 *   php examples/05_courier_pickup.php <package-id> --order     # books the first proposed slot
 *   php examples/05_courier_pickup.php --cancel <package-id>    # cancels an ordered shipment
 *
 * Booking a pickup and cancelling are real actions, so the first is dry unless you give --order.
 * Without an id the script takes the newest ordered shipment of the account.
 *
 * What it shows:
 *   - fetchPickupProposals() lists the days and time windows per shipment; each proposal carries a `hash`
 *     that has to go back with the booking, and PickupProposal::toPickupDate() carries it for you;
 *   - orderPickup() is a command like ordering: a result, not an exception, when the carrier refuses;
 *   - a shipment is cancelled with cancelPackages(). It works up to the `transit` state and is refused after
 *     a delivery. A cancelled shipment stays in the account and leaves a waiting copy to delete.
 */

require __DIR__ . '/bootstrap.php';

exampleRun(static function (): void {
    $client = exampleClient();

    if (exampleFlag('--cancel')) {
        $id = exampleArgument(1) ?? throw new \RuntimeException('Usage: php examples/05_courier_pickup.php --cancel <package-id>');
        $result = $client->cancelPackages([$id]);

        echo $result->isSuccessful() ? "Cancelled {$id}.\n" : 'Not cancelled: ' . implode('; ', array_map('strval', $result->errors)) . PHP_EOL;

        return;
    }

    $id = exampleArgument(1) ?? exampleLatestOrdered($client)->id ?? throw new \RuntimeException('The account has no ordered shipment.');

    exampleHeading("Pickup proposals for shipment {$id}");
    $groups = $client->fetchPickupProposals([$id]);
    $slot = null;

    foreach ($groups as $group) {
        foreach ($group->available() as $proposal) {
            $slot ??= $proposal;
            printf("%s  %s-%s  %s\n", (string) $proposal->date, (string) $proposal->minTime, (string) $proposal->maxTime, (string) $proposal->info);
        }
    }

    if ($slot === null) {
        echo "No slot is available for this shipment.\n";

        return;
    }

    if (!exampleFlag('--order')) {
        echo "\nDry run: nothing was booked. Add --order to book the first slot ({$slot->date}).\n";

        return;
    }

    $result = $client->orderPickup([$id], $slot->toPickupDate());

    echo $result->isSuccessful() ? "Pickup booked for {$slot->date}.\n" : 'Not booked: ' . implode('; ', array_map('strval', $result->errors)) . PHP_EOL;
});
