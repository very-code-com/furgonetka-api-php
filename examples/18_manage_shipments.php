<?php

declare(strict_types=1);

/**
 * Looking after shipments once they exist: companies, changing and deleting waiting shipments, the regulations
 * they need, the confirmation e-mail, and packages from elsewhere on the tracking list.
 *
 *   php examples/18_manage_shipments.php                              # creates, changes and deletes two shipments
 *   php examples/18_manage_shipments.php --send-email <package-id>    # the confirmation e-mail of an ordered shipment
 *   php examples/18_manage_shipments.php --track <waybill> [carrier]  # follows a package from elsewhere, for good
 *   php examples/18_manage_shipments.php --rename <tracked-id> <name> # renames a package on the tracking list
 *
 * Without a flag it creates two waiting shipments and deletes them again, ordering nothing. --track cannot be
 * undone: the API has no call to take a package off the tracking list.
 *
 * What it shows:
 *   - an account with several companies answers per company: withCompany() gives a copy of the client that
 *     sends X-Company-Id with every request, and the tokens are shared;
 *   - a waiting shipment is changed with updatePackage(), which sends the whole shipment again, not a
 *     difference; an ordered one cannot be changed any more, only cancelled;
 *   - regulationsForPackages() names the services whose regulations still stand between the shipments and an
 *     order (empty once they are accepted); pendingRegulations() (see 04_create_and_order.php) gives the
 *     documents themselves, to show and accept;
 *   - deletePackages() removes several waiting shipments at once. A deleted shipment then reads as a 400
 *     `packageOwner`, not a 404, so check the error code rather than the exception class;
 *   - sendDocumentsEmail() is for shipments ordered with skipEmail (or after a pickup was added): once, up to
 *     18 hours after ordering;
 *   - addToTracking() answers 400 `exists` for a number already on the list and `existsSent` for one of your own
 *     ordered shipments; its answer carries the id that fetchTracking() and renameTrackedPackage() take.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

function companies(FurgonetkaApiClient $client): void
{
    exampleHeading('Companies');
    $companies = $client->listCompanies();

    foreach ($companies as $company) {
        printf("%d  %s%s\n", (int) $company->id, (string) $company->name, $company->actual === true ? '  (the one requests act for now)' : '');
    }

    $other = array_values(array_filter($companies, static fn ($c): bool => $c->actual !== true && $c->id !== null))[0] ?? null;

    if ($other === null) {
        echo "One company: withCompany() is not needed on this account.\n";

        return;
    }

    $services = $client->withCompany($other->id)->listServices();
    echo "Acting for company {$other->id}: " . count($services) . " carrier contract(s).\n";
}

function changeAndDelete(FurgonetkaApiClient $client): void
{
    exampleHeading('Two waiting shipments');
    $first = $client->createPackage(exampleShipment($client, Carrier::Dpd, 'Example 18 first'));
    $second = $client->createPackage(exampleShipment($client, Carrier::Dpd, 'Example 18 second'));
    echo "Created {$first->id} and {$second->id}\n";

    try {
        exampleHeading('Change one');
        $changed = $client->updatePackage($first, exampleShipment($client, Carrier::Dpd, 'Example 18 changed'));
        echo "Reference of {$changed->id}: {$client->fetchPackage($first)->userReferenceNumber}\n";

        exampleHeading('Regulations still to accept');
        $services = $client->regulationsForPackages([$first, $second]);
        echo $services === [] ? "None: everything they need is accepted.\n" : 'Not accepted yet: ' . implode(', ', $services) . PHP_EOL;
    } finally {
        exampleHeading('Delete both');
        $client->deletePackages([$first, $second]);
        echo "Deleted.\n";
    }

    try {
        $client->fetchPackage($first);
    } catch (FurgonetkaApiBadRequestException $e) {
        echo 'Reading a deleted shipment: 400' . ($e->hasErrorCode('packageOwner') ? ' packageOwner' : '') . PHP_EOL;
    }
}

function track(FurgonetkaApiClient $client, string $waybill, ?string $carrier): void
{
    try {
        $tracked = $client->addToTracking($waybill, $carrier, 'Example 18');
    } catch (FurgonetkaApiBadRequestException $e) {
        if ($e->hasErrorCode('exists') || $e->hasErrorCode('existsSent')) {
            echo "Already followed, or one of your own shipments: {$e->getMessage()}\n";

            return;
        }

        throw $e;
    }

    echo "Following {$waybill} as {$tracked->id} ({$tracked->carrierName})\n";
    $events = 0;

    foreach ($client->fetchTracking((string) $tracked->id)->parcels as $parcel) {
        $events += count($parcel->events);
    }

    echo "{$events} tracking event(s) so far. Rename it with: --rename {$tracked->id} <name>\n";
}

exampleRun(static function (): void {
    $client = exampleClient();

    if (exampleFlag('--send-email')) {
        $id = exampleArgument(1) ?? throw new \RuntimeException('Usage: php examples/18_manage_shipments.php --send-email <package-id>');
        $client->sendDocumentsEmail($id);
        echo "The confirmation e-mail of {$id} was sent.\n";

        return;
    }

    if (exampleFlag('--track')) {
        $waybill = exampleArgument(1) ?? throw new \RuntimeException('Usage: php examples/18_manage_shipments.php --track <waybill> [carrier]');
        track($client, $waybill, exampleArgument(2));

        return;
    }

    if (exampleFlag('--rename')) {
        $id = exampleArgument(1);
        $name = exampleArgument(2);

        if ($id === null || $name === null) {
            throw new \RuntimeException('Usage: php examples/18_manage_shipments.php --rename <tracked-id> <name>');
        }

        $tracked = $client->renameTrackedPackage($id, $name);
        echo "Tracked package {$tracked->id} is now called \"{$tracked->name}\".\n";

        return;
    }

    companies($client);
    changeAndDelete($client);
});
