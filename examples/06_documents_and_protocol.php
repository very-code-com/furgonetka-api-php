<?php

declare(strict_types=1);

/**
 * Labels in every format, documents for many shipments at once, and the pickup protocol.
 *
 *   php examples/06_documents_and_protocol.php [package-id]
 *
 * Reads only: it takes an ordered shipment (the newest, or the id you give) and downloads its documents
 * into the temporary directory.
 *
 * What it shows:
 *   - fetchLabel() gives the file the shipment was ordered with (PDF, ZPL or EPL); the page size of a PDF
 *     (A4 or A6) is chosen on every download;
 *   - requestDocuments() prepares a ZIP or PDF for many shipments (a command; the URL it returns is signed,
 *     lasts about ten minutes and is fetched without a token by downloadDocuments());
 *   - fetchProtocol() is the pickup protocol, available only for some carriers, and throws
 *     FurgonetkaApiLabelNotReadyException while the carrier has none yet;
 *   - a label that is not there is an exception you can tell apart: a cancelled shipment is a 404, and a
 *     tracked package (one that did not go through Furgonetka) never has one and answers "not ready".
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Enum\DocumentType;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiLabelNotReadyException;

exampleRun(static function (): void {
    $client = exampleClient();
    $id = exampleArgument(1) ?? exampleLatestOrdered($client)->id ?? throw new \RuntimeException('The account has no ordered shipment.');
    $directory = sys_get_temp_dir();

    exampleHeading("Labels of shipment {$id}");
    foreach ([LabelPageFormat::A4, LabelPageFormat::A6] as $format) {
        $label = $client->fetchLabel($id, $format);
        $file = "{$directory}/furgonetka-label-{$id}-{$format->value}.pdf";
        file_put_contents($file, $label->bytes);
        printf("%s: %d bytes, %s -> %s\n", strtoupper($format->value), $label->size(), $label->contentType, $file);
    }

    exampleHeading('Documents of several shipments in one file');
    $documents = $client->requestDocuments([$id], [DocumentType::Labels]);

    if (!$documents->isSuccessful()) {
        echo 'The documents were not prepared: ' . implode('; ', array_map('strval', $documents->errors)) . PHP_EOL;
    } else {
        $file = $client->downloadDocuments($documents);
        $path = "{$directory}/furgonetka-documents-{$id}." . ($file->isZip() ? 'zip' : 'pdf');
        file_put_contents($path, $file->bytes);
        echo "{$file->size()} bytes ({$file->contentType}) -> {$path}\n";
    }

    exampleHeading('Pickup protocol');
    try {
        $protocol = $client->fetchProtocol([$id]);
        $path = "{$directory}/furgonetka-protocol-{$id}.pdf";
        file_put_contents($path, $protocol->bytes);
        echo "{$protocol->size()} bytes -> {$path}\n";
    } catch (FurgonetkaApiLabelNotReadyException) {
        echo "No protocol yet for this shipment (it only exists for some carriers).\n";
    } catch (FurgonetkaApiException $e) {
        echo 'No protocol: ' . $e->getMessage() . PHP_EOL;
    }
});
