<?php

declare(strict_types=1);

/**
 * The local rules, the warnings, and every kind of exception, with no network and no keys.
 *
 *   php examples/10_validation_and_errors.php
 *
 * What it shows:
 *   - validateLocally() returns the rules that Furgonetka enforces too (an error here is an error there);
 *     warningsFor() returns rules that the documentation states and the API accepts a violation of. Neither
 *     needs a request. validateRemotely() is the authority;
 *   - createPackage() runs the local rules first and throws FurgonetkaApiValidationException without
 *     sending anything;
 *   - each HTTP status has its own exception class, all below FurgonetkaApiException, so `catch` can be as
 *     narrow as you need. A carrier's rejection inside a command is not among them: it is a result.
 */

use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Pickup;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiHttpException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiRateLimitException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

require __DIR__ . '/../vendor/autoload.php';

/** Answers with the next queued answer or throws the next queued exception. */
final class CannedTransport implements TransportInterface
{
    /** @param list<TransportResponse|\Throwable> $answers */
    public function __construct(private array $answers)
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $next = array_shift($this->answers) ?? throw new \LogicException('No answer left.');

        return $next instanceof \Throwable ? throw $next : $next;
    }
}

function json(string $body, int $status): TransportResponse
{
    return new TransportResponse($status, $body, ['content-type' => 'application/json']);
}

function shipment(?string $receiverName = 'Anna Nowak', int $parcels = 1, ?LabelSender $labelSender = null): ShipmentRequest
{
    return new ShipmentRequest(
        serviceId: 1,
        pickup: new Pickup('Marszalkowska 100', '00-026', 'Warszawa', name: 'Jan Kowalski', countryCode: 'PL', email: 'jan@example.com', phone: '500600700'),
        receiver: new Receiver(name: $receiverName, street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'anna@example.com', phone: '600700800'),
        parcels: array_fill(0, $parcels, Parcel::package(30, 40, 20, 5.0, 100.0, 'Books')),
        labelSender: $labelSender,
    );
}

/**
 * A client whose transport answers with the given answers, in order.
 *
 * @param list<TransportResponse|\Throwable> $answers
 */
function clientAnswering(array $answers = []): FurgonetkaApiClient
{
    return new FurgonetkaApiClient(FurgonetkaApiConfig::sandbox(), new CannedTransport($answers), new StaticTokenProvider('a-token'));
}

echo "1. Local rules (no request)\n";
$offline = clientAnswering();

foreach ([
    'a valid shipment' => shipment(),
    'a receiver name of two short words' => shipment('A B'),
    'sixteen parcels with DPD' => shipment(parcels: 16),
] as $label => $request) {
    $errors = $offline->validateLocally($request, Carrier::Dpd);
    echo sprintf("  %-38s %s\n", $label, $errors === [] ? 'fine' : implode(' | ', $errors));
}

echo "\n2. Warnings: rules the documentation states that the API does not always enforce\n";
$otherSender = new LabelSender('Prosta 1', '00-838', 'Warszawa', name: 'Shop Name', email: 'shop@example.com', phone: '500100200');

foreach ([
    'label data differ from the pickup' => [shipment(labelSender: $otherSender), Carrier::Dpd],
    'label data with GLS' => [shipment(labelSender: $otherSender), Carrier::Gls],
] as $label => [$request, $carrier]) {
    echo sprintf("  %s:\n    %s\n", $label, implode("\n    ", $offline->warningsFor($request, $carrier)));
}

echo "\n3. createPackage() refuses a shipment that is certainly wrong, before any request\n";
try {
    $offline->createPackage(shipment('A B'));
} catch (FurgonetkaApiValidationException $e) {
    echo '  ' . count($e->errors) . ' error(s): ' . implode(' | ', $e->errors) . PHP_EOL;
}

echo "\n4. Every failure has its own class\n";
$cases = [
    'invalid field (400)' => json('{"errors":[{"path":"/receiver/postcode","message":"Bad postcode","details":"","code":"invalid"}]}', 400),
    'bad token (401)' => json('{"code":"access_denied","message":"The resource owner or authorization server denied the request.","details":"Error while decoding from JSON"}', 401),
    'not found (404)' => json('{"errors":[{"path":"/package_id","message":"Package cancelled","details":"","code":""}]}', 404),
    'regulations not accepted (409)' => json('{"code":"terms_and_conditions_not_valid","message":"Must accept terms and conditions","details":""}', 409),
    'too many requests (429)' => new TransportResponse(429, '{"code":"rate_limit","message":"Too many requests","details":""}', ['content-type' => 'application/json', 'x-ratelimit-retry-after' => '42']),
    'server error (500)' => json('{"code":"error","message":"Unhandled error. Please try again.","details":""}', 500),
    'network failure' => new FurgonetkaApiTransportException('Operation timed out'),
];

foreach ($cases as $label => $answer) {
    try {
        clientAnswering([$answer])->fetchPackage('21351614');
    } catch (FurgonetkaApiBadRequestException $e) {
        printf("  %-32s FurgonetkaApiBadRequestException: paths %s, code invalid: %s\n", $label, implode(',', $e->getFieldPaths()), var_export($e->hasErrorCode('invalid'), true));
    } catch (FurgonetkaApiAuthException $e) {
        printf("  %-32s FurgonetkaApiAuthException: %s\n", $label, $e->getMessage());
    } catch (FurgonetkaApiNotFoundException $e) {
        printf("  %-32s FurgonetkaApiNotFoundException: paths %s\n", $label, implode(',', $e->getFieldPaths()));
    } catch (FurgonetkaApiConflictException) {
        printf("  %-32s FurgonetkaApiConflictException: accept the carrier's regulations (pendingRegulations(), acceptRegulations())\n", $label);
    } catch (FurgonetkaApiRateLimitException $e) {
        printf("  %-32s FurgonetkaApiRateLimitException: retry after %s s\n", $label, (string) $e->retryAfterSeconds());
    } catch (FurgonetkaApiServerException $e) {
        printf("  %-32s FurgonetkaApiServerException: retryable %s\n", $label, var_export($e->isRetryable(), true));
    } catch (FurgonetkaApiTransportException $e) {
        printf("  %-32s FurgonetkaApiTransportException: %s\n", $label, $e->getMessage());
    }
}

echo "\n5. One catch for everything from the library\n";
try {
    clientAnswering([json('{"errors":[{"path":"/id","message":"Nope","details":"","code":"x"}]}', 400)])->fetchPackage('1');
} catch (FurgonetkaApiHttpException $e) {
    echo '  HTTP ' . $e->statusCode . ', ' . count($e->errors) . " error(s); FurgonetkaApiException is the base of all of them.\n";
} catch (FurgonetkaApiException $e) {
    echo '  ' . $e->getMessage() . PHP_EOL;
}
