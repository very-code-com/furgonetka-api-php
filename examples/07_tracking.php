<?php

declare(strict_types=1);

/**
 * Tracking: the history of a shipment, following a package that did not go through Furgonetka, and (on the
 * sandbox only) simulating states.
 *
 *   php examples/07_tracking.php [package-id]
 *   php examples/07_tracking.php <package-id> --simulate     # sandbox only: rewrites that shipment's history
 *
 * Without --simulate it only reads. --simulate changes the shipment you name (the parcel goes back to
 * `ordered` and its history becomes synthetic), so use a sandbox shipment you do not care about.
 *
 * What it shows:
 *   - fetchTracking() takes Furgonetka's shipment id, not a waybill number (that is a 404), and answers per
 *     waybill number; TrackingResult::numbers() lists them and parcel() finds one;
 *   - an event has a normalised `state` to branch on and the carrier's own wording in `status`, which is free
 *     text in the carrier's language: show it, never match on it;
 *   - addToTracking() follows a waybill number of another system; such a package cannot be removed again;
 *   - simulateTracking() walks a sandbox shipment through the states, to try tracking and webhooks without a
 *     courier. The API takes only the states from the enum minus two it refuses (checked locally).
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;

exampleRun(static function (): void {
    $client = exampleClient();
    $package = exampleArgument(1) !== null ? $client->fetchPackage((string) exampleArgument(1)) : exampleLatestOrdered($client);

    if ($package === null || $package->id === null) {
        throw new \RuntimeException('The account has no ordered shipment.');
    }

    if (exampleFlag('--simulate')) {
        if (exampleConfig()->getEnvironment() !== 'sandbox') {
            throw new \RuntimeException('Simulation exists only on the sandbox (FURGONETKA_ENV=sandbox).');
        }

        foreach ([TrackingState::Collected, TrackingState::Transit, TrackingState::Delivery, TrackingState::Delivered] as $state) {
            $client->simulateTracking($package, $state);
            echo "Simulated {$state->value}\n";
        }
    }

    exampleHeading("Tracking of shipment {$package->id}");
    $tracking = $client->fetchTracking($package);

    foreach ($tracking->numbers() as $number) {
        $parcel = $tracking->parcel($number);

        if ($parcel === null) {
            continue;
        }

        echo "Waybill {$number}: " . ($parcel->state()->value ?? 'no state yet') . ($parcel->isDelivered() ? ' (delivered)' : '') . PHP_EOL;

        foreach ($parcel->events as $event) {
            printf("  %s  %-22s %s%s\n", $event->datetime?->format('Y-m-d H:i') ?? '-', $event->stateValue ?? '?', (string) $event->status, $event->branch !== null ? " [{$event->branch}]" : '');
        }

        if ($parcel->deliveryTrackingUrl !== null) {
            echo "  Link for the recipient: {$parcel->deliveryTrackingUrl}\n";
        }
    }

    exampleHeading('Following a waybill number from elsewhere');
    echo "addToTracking('<waybill>', Carrier::Dpd, 'My name') follows a package that was not made here.\n"
        . "It is added for good (the API has no call to remove it), so this example only shows the call.\n";
});
