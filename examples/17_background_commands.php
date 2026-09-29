<?php

declare(strict_types=1);

/**
 * Commands in the background: submit now, read the result later from a queue worker or a cron job, instead of
 * keeping a web request waiting.
 *
 *   php examples/17_background_commands.php submit [package-id]           # documents of an ordered shipment
 *   php examples/17_background_commands.php submit --order <package-id>   # orders a waiting shipment (sandbox)
 *   php examples/17_background_commands.php work                          # reads every pending job once
 *   php examples/17_background_commands.php abort                         # submits a CSV export and aborts it
 *
 * The jobs are kept in a JSON file in the temporary directory, standing in for a table of your application.
 * `submit` without --order changes nothing on the account (it prepares a PDF); --order books a carrier, so it
 * refuses to run anywhere but on the sandbox. Without an id `submit` takes the newest ordered shipment.
 *
 * What it shows:
 *   - orderPackages(), requestDocuments() and the other waiting methods are submit*() plus fetch*() in a loop.
 *     Call the halves yourself when the result can come later: submit*() returns as soon as Furgonetka has
 *     accepted the command, fetch*() reads it once and never sleeps;
 *   - choose the UUID yourself and store it BEFORE you submit. If the connection drops you do not know whether
 *     Furgonetka got the command; submitting again with the same UUID is safe (Furgonetka answers
 *     `commandExists`, the client takes that as done and returns the UUID), so a queue can simply retry the job;
 *   - a result that is not finished yet is not an error: the worker leaves the job for its next run. A carrier
 *     that refuses a shipment is a finished result with errors, not an exception;
 *   - abortCommand() works only while the command is still queued, which is a second or two: afterwards it is a
 *     400 `wrongState`. An aborted command ends as `cancelled` (CommandResult::isCancelled()).
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Command\CommandType;
use VeryCodeCom\FurgonetkaApi\Dto\DocumentsCommandResult;
use VeryCodeCom\FurgonetkaApi\Enum\CsvRange;
use VeryCodeCom\FurgonetkaApi\Enum\DocumentType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;

/** The stand-in for a jobs table: UUID => ['kind' => 'order'|'documents', 'package' => id]. */
function jobsFile(): string
{
    return sys_get_temp_dir() . '/furgonetka-api-php-examples-' . exampleConfig()->getEnvironment() . '-jobs.json';
}

/** @return array<string, array{kind: string, package: string}> */
function loadJobs(): array
{
    $json = is_file(jobsFile()) ? file_get_contents(jobsFile()) : false;
    /** @var array<string, array{kind: string, package: string}>|null $jobs */
    $jobs = is_string($json) ? json_decode($json, true) : null;

    return is_array($jobs) ? $jobs : [];
}

/** @param array<string, array{kind: string, package: string}> $jobs */
function saveJobs(array $jobs): void
{
    file_put_contents(jobsFile(), json_encode($jobs, JSON_PRETTY_PRINT));
}

function submitJob(FurgonetkaApiClient $client): void
{
    $order = exampleFlag('--order');

    if ($order && exampleConfig()->getEnvironment() !== 'sandbox') {
        throw new \RuntimeException('Refusing to order outside the sandbox (FURGONETKA_ENV=sandbox).');
    }

    $package = exampleArgument(2) ?? ($order ? null : exampleLatestOrdered($client)?->id)
        ?? throw new \RuntimeException($order ? 'Usage: php examples/17_background_commands.php submit --order <waiting-package-id>' : 'The account has no ordered shipment.');
    $kind = $order ? 'order' : 'documents';

    // 1. The UUID is chosen and stored first, so that a crash after this line still leaves a job to retry.
    $uuid = Uuid::v4();
    $jobs = loadJobs();
    $jobs[$uuid] = ['kind' => $kind, 'package' => $package];
    saveJobs($jobs);

    // 2. Submit. On a network error the exception carries the same UUID: the job stays and is simply submitted again.
    $submit = static fn (): string => $order
        ? $client->submitOrder([$package], uuid: $uuid)
        : $client->submitDocuments([$package], [DocumentType::Labels], uuid: $uuid);

    try {
        $submit();
    } catch (FurgonetkaApiTransportException $e) {
        echo "Network error, the job {$e->commandUuid()} stays queued: {$e->getMessage()}\n";

        return;
    }

    echo "Submitted {$kind} of shipment {$package} as {$uuid}\n";

    // 3. What a retry looks like: the same UUID again is not a second order, the client answers with the UUID.
    echo 'Submitted again with the same UUID: ' . ($submit() === $uuid ? 'accepted as already done' : 'unexpected') . PHP_EOL;
    echo "Now run: php examples/17_background_commands.php work\n";
}

function work(FurgonetkaApiClient $client): void
{
    $jobs = loadJobs();

    if ($jobs === []) {
        echo "No pending jobs. Submit one first.\n";

        return;
    }

    foreach ($jobs as $uuid => $job) {
        $result = $job['kind'] === 'order' ? $client->fetchOrderCommand($uuid) : $client->fetchDocumentsCommand($uuid);

        if (!$result->isFinished()) {
            // Not an error: queueing or running, or a status newer than this library (statusValue shows it).
            echo "{$uuid} ({$job['kind']}): still {$result->statusValue}, left for the next run\n";

            continue;
        }

        if ($result->isSuccessful()) {
            $extra = $result instanceof DocumentsCommandResult ? ", file at {$result->url} (valid for about 10 minutes)" : '';
            echo "{$uuid} ({$job['kind']}): done{$extra}\n";
        } else {
            $errors = $result->errorsFor($job['package']) ?: $result->errors;
            echo "{$uuid} ({$job['kind']}): {$result->statusValue}: " . implode('; ', array_map('strval', $errors)) . PHP_EOL;
        }

        unset($jobs[$uuid]);
    }

    saveJobs($jobs);
}

function abort(FurgonetkaApiClient $client): void
{
    $uuid = $client->submitCsvExport(CsvRange::Last30Days);
    echo "Submitted a CSV export as {$uuid}\n";

    try {
        $client->abortCommand($uuid, CommandType::CsvExport);
    } catch (FurgonetkaApiBadRequestException $e) {
        if (!$e->hasErrorCode('wrongState')) {
            throw $e;
        }

        echo "Too late: the export had already started, and a started command cannot be aborted.\n";

        return;
    }

    echo 'Aborted in time; the command now reads as ' . $client->fetchCsvExport($uuid)->statusValue . PHP_EOL;
}

exampleRun(static function (): void {
    $client = exampleClient();

    match (exampleArgument(1)) {
        'submit' => submitJob($client),
        'work' => work($client),
        'abort' => abort($client),
        default => throw new \RuntimeException('Usage: php examples/17_background_commands.php submit|work|abort (see the comment at the top).'),
    };
});
