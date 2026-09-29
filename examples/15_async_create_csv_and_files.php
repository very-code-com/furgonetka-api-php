<?php

declare(strict_types=1);

/**
 * Commands that run on Furgonetka's side: shipments created as a command, a CSV export, a cart re-check, HS
 * codes, adding to tracking, and uploading files.
 *
 *   php examples/15_async_create_csv_and_files.php
 *
 * It creates one waiting shipment as a command and deletes it again, exports the last 30 days as CSV, asks
 * for HS codes and uploads a small text file. Ordering nothing.
 *
 * What it shows:
 *   - every command is `PUT /<x>-command/{uuid}` and then reads until the status is final. exportPackagesCsv()
 *     and friends wait for you; submit*() returns the UUID at once and fetch*() reads it later, which is the
 *     way for a web request that must not wait (17_background_commands.php shows it);
 *   - the UUID may be your own. After a network error, call again with the same UUID and the client reads the
 *     result instead of doing it twice;
 *   - revalidatePackages() checks shipments in the cart again (waiting ones; an ordered shipment is a 404);
 *   - a command aborted while queued ends as `cancelled` (CommandResult::isCancelled()); abortCommand()
 *     only lands before the command starts, which is within a second or two;
 *   - the CSV link is signed and short-lived: downloadCsvExport() fetches it without your token;
 *   - HS code search needs the token although the specification lists none; a carrier the account may not use
 *     ends as an `error` result;
 *   - an upload answers two UUIDs (the command's and `file_uuid`). A shipment's photos take the `file_uuid` of a
 *     file uploaded with the type `packagePhoto`; which UUID customs documents want is not settled, because
 *     the sandbox accepts any UUID there.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\CsvRange;
use VeryCodeCom\FurgonetkaApi\Enum\HsCodeService;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;

exampleRun(static function (): void {
    $client = exampleClient();

    exampleHeading('A shipment created as a command');
    $uuid = Uuid::v4();
    $created = $client->createPackageAsync(exampleShipment($client, Carrier::Dpd, 'Async example'), $uuid);

    if ($created->isSuccessful() && $created->packageId !== null) {
        echo "Created shipment {$created->packageId} under {$uuid}\n";

        // A waiting shipment is "in the cart": Furgonetka can check it again before it is ordered.
        $check = $client->revalidatePackages([$created->packageId]);
        echo 'Checked again: ' . ($check->success === true ? 'it passes' : 'it does not pass') . PHP_EOL;

        $client->deletePackage($created->packageId);
        echo "Deleted it again.\n";
    } else {
        echo 'Not created: ' . implode('; ', array_map('strval', $created->errors)) . PHP_EOL;
    }

    exampleHeading('CSV export of the last 30 days');
    $export = $client->exportPackagesCsv(CsvRange::Last30Days);

    if ($export->isSuccessful()) {
        $file = $client->downloadCsvExport($export);
        $lines = substr_count($file->bytes, "\n");
        echo "{$file->size()} bytes, {$lines} lines; first line: " . substr((string) strtok($file->bytes, "\n"), 0, 90) . "...\n";
    } else {
        echo 'No export: ' . implode('; ', array_map('strval', $export->errors)) . PHP_EOL;
    }

    exampleHeading('HS codes');
    $codes = $client->searchHsCodes(HsCodeService::NovaPost, 'shoes');
    echo $codes->isSuccessful() ? count($codes->codes()) . ' codes, for instance ' . implode(', ', array_slice($codes->codes(), 0, 3)) . PHP_EOL : 'No codes: ' . implode('; ', array_map('strval', $codes->errors)) . PHP_EOL;

    exampleHeading('Upload a file');
    $upload = $client->uploadFile('note.txt', "An example file.\n", contentType: 'text/plain');
    echo $upload->isSuccessful() ? "Stored. Command {$upload->uuid}, file UUID {$upload->fileUuid}\n" : 'Not stored: ' . implode('; ', array_map('strval', $upload->errors)) . PHP_EOL;
    echo "Uploaded files cannot be removed through the API, so this example leaves one behind on every run.\n";
});
