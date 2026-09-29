<?php

declare(strict_types=1);

/**
 * Wiring the client into an application and testing code that uses it, with no network and no keys.
 *
 *   php examples/09_di_and_testing.php
 *
 * What it shows:
 *   - the client takes a TransportInterface (one method: send(TransportRequest): TransportResponse), a
 *     PSR-3 logger and a token provider. A scripted transport answers your tests; a PSR-18 adapter would
 *     carry the same interface in production;
 *   - StaticTokenProvider serves a token you manage yourself; FurgonetkaApiClient::create() with a token
 *     store is what an application normally uses (see 02_password_grant_and_2fa.php);
 *   - the requests the client sent are readable afterwards, and TransportRequest::redacted() masks the
 *     Authorization header, so a request can be logged;
 *   - nothing secret reaches the logger or an exception message: the run below checks it.
 */

use Psr\Log\AbstractLogger;
use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

require __DIR__ . '/../vendor/autoload.php';

/** Answers with what you queue, in order, and remembers what it was asked. */
final class ScriptedTransport implements TransportInterface
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @param list<TransportResponse> $answers */
    public function __construct(private array $answers)
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;

        return array_shift($this->answers) ?? throw new \LogicException('No answer left for ' . $request->describe());
    }

    public static function json(string $body, int $status = 200): TransportResponse
    {
        return new TransportResponse($status, $body, ['content-type' => 'application/json']);
    }
}

/** A PSR-3 logger that keeps its lines. */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, mixed $message, array $context = []): void
    {
        $this->lines[] = strtoupper((string) $level) . ' ' . $message . ($context === [] ? '' : ' ' . json_encode($context));
    }
}

// The application's side: a class that depends on the client and knows nothing about HTTP.
final class ShippingCosts
{
    public function __construct(private readonly FurgonetkaApiClient $client)
    {
    }

    /**
     * The names of the carriers the account has a contract with.
     *
     * @return list<string>
     */
    public function carriers(): array
    {
        $names = [];

        foreach ($this->client->listServices() as $service) {
            $names[] = (string) $service->name;
        }

        return $names;
    }
}

// The test's side.
$secret = 'do-not-log-this-token';
$transport = new ScriptedTransport([
    ScriptedTransport::json('{"services":[{"id":1,"service":"dpd","name":"DPD","owner":"furgonetka"},{"id":2,"service":"gls","name":"GLS","owner":"furgonetka"}]}'),
    ScriptedTransport::json('{"errors":[{"path":"/id","message":"Entry does not exist.","details":"","code":"notExists"}]}', 400),
]);
$logger = new MemoryLogger();

$client = new FurgonetkaApiClient(
    config: FurgonetkaApiConfig::sandbox(),
    transport: $transport,
    tokens: new StaticTokenProvider($secret),
    logger: $logger,
    polling: new PollingPolicy(timeout: 5.0),
);

echo 'Carriers: ' . implode(', ', (new ShippingCosts($client))->carriers()) . PHP_EOL;

$sent = $transport->requests[0];
echo 'Sent: ' . $sent->describe() . ' with Accept ' . $sent->header('Accept') . PHP_EOL;
echo 'Authorization as it would be logged: ' . $sent->redacted()->header('Authorization') . PHP_EOL;

try {
    $client->fetchAddressBookEntry(999);
} catch (FurgonetkaApiBadRequestException $e) {
    echo 'Error handled: ' . $e->getMessage() . PHP_EOL;
    echo 'Field paths: ' . implode(', ', $e->getFieldPaths()) . ', code notExists: ' . var_export($e->hasErrorCode('notExists'), true) . PHP_EOL;

    if (str_contains($e->getTraceAsString(), $secret) || str_contains(print_r($e->getTrace(), true), $secret)) {
        fwrite(STDERR, "The token leaked into a stack trace.\n");
        exit(1);
    }
}

if (str_contains(implode("\n", $logger->lines), $secret) || str_contains(print_r($transport->requests[1]->redacted(), true), $secret)) {
    fwrite(STDERR, "The token leaked into the log.\n");
    exit(1);
}

echo "Logged:\n  " . implode("\n  ", $logger->lines) . PHP_EOL;
echo "The token appears nowhere in the log or in a trace.\n";
