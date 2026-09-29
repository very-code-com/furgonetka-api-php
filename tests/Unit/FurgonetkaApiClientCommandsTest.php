<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Command\CommandType;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Dto\DocumentsCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\LabelOptions;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\PickupDate;
use VeryCodeCom\FurgonetkaApi\Enum\DocumentType;
use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiGoneException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiHttpException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiLabelNotReadyException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Ordering, pickup, cancellation, documents, labels and the protocol through the client, with the
 * bodies the sandbox gave: the five command states, the timeout, the 409, the errors that belong to
 * one shipment, the 204 of a label that is not there yet, and the retry that must not order twice.
 */
final class FurgonetkaApiClientCommandsTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';
    private const UUID = '45b65793-f50d-4edb-b09f-5ff3bcd14abf';
    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private static function pdf(): TransportResponse
    {
        return new TransportResponse(200, self::PDF, ['content-type' => 'application/pdf']);
    }

    /** The UUID the client put into the first request's URL. */
    private static function uuidOf(ClientHarness $h, int $index = 0): string
    {
        $url = $h->transport->requests[$index]->url;
        preg_match('#/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$#D', $url, $match);

        return $match[1] ?? throw new \LogicException("No version 4 UUID at the end of {$url}.");
    }

    // -- Ordering: the states of a command --------------------------------------------------------

    public function testAnOrderIsSubmittedUnderAFreshUuidAndReadUntilItIsSuccessful(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/order-running'), Fixtures::response('commands/order-successful')]);

        $result = $h->client->orderPackages(['21353373']);

        self::assertSame(CommandStatus::Successful, $result->status);
        self::assertSame(['21353373'], $result->successfulPackageIds());

        $uuid = self::uuidOf($h);
        self::assertSame(
            ["PUT " . self::HOST . "/order-commands/{$uuid}", "GET " . self::HOST . "/order-commands/{$uuid}", "GET " . self::HOST . "/order-commands/{$uuid}"],
            $h->sent(),
        );
        self::assertSame(['packages' => [['id' => '21353373']]], $h->body(), 'No label and no skip_email_send unless asked for.');
        self::assertSame([1.0], $h->sleeper->sleeps, 'One wait, between the running read and the successful one.');
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Content-Type'));
    }

    public function testAQueueingCommandIsReadAgain(): void
    {
        $h = new ClientHarness([
            Fixtures::response('commands/put-accepted'),
            FakeTransport::jsonResponse('{"status":"queueing","errors":[],"uuid":"' . self::UUID . '","successfully_ordered_packages":[]}'),
            Fixtures::response('commands/order-running'),
            Fixtures::response('commands/order-successful'),
        ]);

        $result = $h->client->orderPackages(['21353373']);

        self::assertTrue($result->isSuccessful());
        self::assertSame([1.0, 1.0], $h->sleeper->sleeps);
    }

    public function testAnOrderThatIsDoneAtOnceIsNotWaitedFor(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/order-successful')]);

        $h->client->orderPackages(['21353373']);

        self::assertSame([], $h->sleeper->sleeps);
    }

    public function testACarrierThatRejectsAShipmentIsAResultNotAnException(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/documented/order-error')]);

        $result = $h->client->orderPackages(['90014858123']);

        self::assertTrue($result->hasFailed());
        self::assertFalse($result->isSuccessful());
        self::assertSame(['90014858123'], $result->failedPackageIds());
        self::assertSame('carrierValidationError', $result->errorsFor('90014858123')[0]->code);
    }

    public function testAPartialOrderReturnsBothHalves(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/documented/order-partial-success')]);

        $result = $h->client->orderPackages(['90014858123', '90014858124']);

        self::assertTrue($result->isPartial());
        self::assertSame(['90014858124'], $result->successfulPackageIds());
        self::assertSame(['90014858123'], $result->failedPackageIds());
        self::assertSame([], $result->generalErrors());
    }

    public function testACommandThatNeverFinishesEndsInATimeoutThatCarriesTheUuid(): void
    {
        $running = Fixtures::response('commands/order-running');
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), ...array_fill(0, 20, $running)], polling: new PollingPolicy(interval: 1.0, timeout: 3.0));

        try {
            $h->client->orderPackages(['21353373']);
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid(), 'The order is not lost: read it later with this UUID instead of ordering again.');
            self::assertSame('running', $e->lastStatus());
        }

        self::assertEqualsWithDelta(3.0, $h->sleeper->total(), 0.0001, 'The waits add up to the timeout and not beyond it.');
        self::assertCount(1 + 4, $h->transport->requests, 'One PUT and four reads: at 0, 1, 2 and 3 seconds.');
    }

    public function testAPerCallPolicyOverridesTheDefault(): void
    {
        $running = Fixtures::response('commands/order-running');
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), $running, $running, $running]);

        try {
            $h->client->orderPackages(['21353373'], policy: new PollingPolicy(interval: 0.5, timeout: 1.0));
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException) {
        }

        self::assertSame([0.5, 0.5], $h->sleeper->sleeps);
    }

    public function testAnUnknownStatusIsWaitedOutAndReportedInTheTimeout(): void
    {
        $paused = FakeTransport::jsonResponse('{"status":"paused","errors":[],"uuid":"' . self::UUID . '"}');
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), $paused, $paused, $paused], polling: new PollingPolicy(timeout: 2.0));

        try {
            $h->client->orderPackages(['21353373']);
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame('paused', $e->lastStatus());
        }
    }

    public function testAnOrderCanBeReadLaterWithoutWaiting(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/order-running'), Fixtures::response('commands/order-successful')]);

        self::assertFalse($h->client->fetchOrderCommand(self::UUID)->isFinished());
        self::assertTrue($h->client->fetchOrderCommand(self::UUID)->isSuccessful());
        self::assertSame([], $h->sleeper->sleeps);
        self::assertSame('GET ' . self::HOST . '/order-commands/' . self::UUID, $h->sent()[0]);
    }

    // -- Ordering: what is sent -----------------------------------------------------------------------

    public function testTheLabelFormatAndTheSkippedEmailAreSent(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $uuid = $h->client->submitOrder([21353373, new Package(id: '21353374')], LabelOptions::pdf(LabelPageFormat::A6, true), skipEmail: true);

        self::assertSame(self::uuidOf($h), $uuid);
        self::assertSame(
            [
                'packages' => [['id' => '21353373'], ['id' => '21353374']],
                'label' => ['file_format' => 'pdf', 'page_format' => 'a6', 'add_cutting_line' => true],
                'skip_email_send' => true,
            ],
            $h->body(),
        );
    }

    public function testAZplLabelIsRequestedAtOrderingBecauseItCannotBeChangedLater(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $h->client->submitOrder(['21353373'], LabelOptions::zpl());

        self::assertSame(['file_format' => 'zpl'], $h->body()['label']);
    }

    public function testAGeneratedUuidIsAVersion4UuidAndIsNeverReused(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/put-accepted')]);

        $first = $h->client->submitOrder(['1']);
        $second = $h->client->submitOrder(['1']);

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $first);
    }

    public function testACallerUuidIsUsedAsItIs(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        self::assertSame(self::UUID, $h->client->submitOrder(['1'], uuid: self::UUID));
        self::assertSame('PUT ' . self::HOST . '/order-commands/' . self::UUID, $h->sent()[0]);
    }

    /** @return iterable<string, array{callable(FurgonetkaApiClient): mixed, string}> */
    public static function badCommandInput(): iterable
    {
        yield 'no shipments' => [static fn (FurgonetkaApiClient $c) => $c->submitOrder([]), 'At least one shipment'];
        yield '101 shipments' => [static fn (FurgonetkaApiClient $c) => $c->submitOrder(array_map('strval', range(1, 101))), 'At most 100'];
        yield 'a waybill number' => [static fn (FurgonetkaApiClient $c) => $c->submitOrder(['0000888261798Q']), 'not a waybill number'];
        yield 'a uuid that is not one' => [static fn (FurgonetkaApiClient $c) => $c->submitOrder(['1'], uuid: 'not-a-uuid'), 'UUID'];
        yield 'a uuid with a trailing newline' => [static fn (FurgonetkaApiClient $c) => $c->submitOrder(['1'], uuid: self::UUID . "\n"), 'UUID'];
        yield 'reading a command with a bad uuid' => [static fn (FurgonetkaApiClient $c) => $c->fetchOrderCommand('../packages'), 'UUID'];
        yield 'no document types' => [static fn (FurgonetkaApiClient $c) => $c->submitDocuments(['1'], []), 'at least one document type'];
        yield 'a pickup for nothing' => [static fn (FurgonetkaApiClient $c) => $c->submitPickup([], new PickupDate('2026-10-01')), 'At least one shipment'];
        yield 'a cancellation of nothing' => [static fn (FurgonetkaApiClient $c) => $c->submitCancel([]), 'At least one shipment'];
        yield 'reading a cancellation with a bad uuid' => [static fn (FurgonetkaApiClient $c) => $c->fetchCancelCommand('../cancel-command'), 'UUID'];
        yield 'a protocol for nothing' => [static fn (FurgonetkaApiClient $c) => $c->fetchProtocol([]), 'At least one shipment'];
        yield 'pickup proposals for 0 days' => [static fn (FurgonetkaApiClient $c) => $c->fetchPickupProposals(['1'], daysAhead: 0), 'between 1 and 7'];
        yield 'pickup proposals for 8 days' => [static fn (FurgonetkaApiClient $c) => $c->fetchPickupProposals(['1'], daysAhead: 8), 'between 1 and 7'];
        yield 'abort with a bad uuid' => [static fn (FurgonetkaApiClient $c) => $c->abortCommand('x'), 'UUID'];
    }

    /** @param callable(FurgonetkaApiClient): mixed $call */
    #[DataProvider('badCommandInput')]
    public function testBadInputIsRefusedBeforeAnythingIsSent(callable $call, string $message): void
    {
        $h = new ClientHarness();

        try {
            $call($h->client);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testExactlyOneHundredShipmentsAreAllowed(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $h->client->submitOrder(array_map('strval', range(1, 100)));

        self::assertCount(100, $h->body()['packages']);
    }

    // -- 409: the regulations -------------------------------------------------------------------------------

    public function testACarrierRegulationThatIsNotAcceptedIsA409AndNothingIsPolled(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/conflict-terms-and-conditions', 409)]);

        try {
            $h->client->orderPackages(['21353373']);
            self::fail('Expected a conflict.');
        } catch (FurgonetkaApiConflictException $e) {
            self::assertSame(409, $e->statusCode);
            self::assertTrue($e->hasErrorCode('terms_and_conditions_not_valid'));
            self::assertStringContainsString('regulations', $e->getMessage());
        }

        self::assertCount(1, $h->transport->requests);
        self::assertSame([], $h->sleeper->sleeps);
        self::assertTrue($h->logger->has(LogLevel::ERROR, 'regulations'));
    }

    public function testAPickupAnswers409TheSameWay(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/conflict-terms-and-conditions', 409)]);

        $this->expectException(FurgonetkaApiConflictException::class);

        $h->client->orderPickup(['21353373'], new PickupDate('2026-10-01', '09:00', '17:00', 'hash'));
    }

    // -- A retry must not order twice ---------------------------------------------------------------------------

    public function testARetryWithTheSameUuidTakesCommandExistsAsAlreadySubmitted(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400), Fixtures::response('commands/order-successful')]);

        $result = $h->client->orderPackages(['21353373'], uuid: self::UUID);

        self::assertTrue($result->isSuccessful());
        self::assertSame(['PUT ' . self::HOST . '/order-commands/' . self::UUID, 'GET ' . self::HOST . '/order-commands/' . self::UUID], $h->sent());
        self::assertTrue($h->logger->has(LogLevel::INFO, 'already exists'));
    }

    public function testTheRetryOfAPickupAnswersWithTwoErrorsAndStillCounts(): void
    {
        // The sandbox answered a repeated pickup with this body: the shipment is "sent" and the command exists.
        $h = new ClientHarness([Fixtures::response('errors/bad-request-already-sent', 400), Fixtures::response('commands/pickup-successful')]);

        $result = $h->client->orderPickup(['21353373'], new PickupDate('2026-09-29', '09:00', '17:00', 'hash'), uuid: self::UUID);

        self::assertTrue($result->isSuccessful(), 'The command exists, so what the caller wants is its result, not the second error.');
        self::assertSame('GET ' . self::HOST . '/pickup-commands/' . self::UUID, $h->sent()[1]);
    }

    public function testACommandExistsAnswerToAGeneratedUuidIsAnError(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400)]);

        $this->expectException(FurgonetkaApiBadRequestException::class);

        $h->client->orderPackages(['21353373']);
    }

    public function testAShipmentThatWasOrderedBeforeIsAnErrorForANewUuid(): void
    {
        $body = '{"errors":[{"path":"/packages/0/id","message":"The package has already been ordered.","details":"","code":"sent"}]}';
        $h = new ClientHarness([FakeTransport::jsonResponse($body, 400)]);

        try {
            $h->client->orderPackages(['21353373'], uuid: self::UUID);
            self::fail('Expected a bad request.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('sent'));
            self::assertSame(['/packages/0/id'], $e->getFieldPaths());
        }
    }

    public function testANetworkFailureOnSubmitCarriesTheUuidForTheRetry(): void
    {
        $h = new ClientHarness([new FurgonetkaApiTransportException('Operation timed out')]);

        try {
            $h->client->submitOrder(['21353373']);
            self::fail('Expected a transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid(), 'Whether the server got the request is unknown: retry with this UUID.');
        }
    }

    public function testANetworkFailureWhilePollingCarriesTheUuidToo(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), new FurgonetkaApiTransportException('Connection reset')]);

        try {
            $h->client->orderPackages(['21353373']);
            self::fail('Expected a transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid());
        }
    }

    public function testAServerErrorOnSubmitIsAnException(): void
    {
        $h = new ClientHarness([new TransportResponse(502, 'Bad Gateway', ['content-type' => 'text/html'])]);

        $this->expectException(FurgonetkaApiServerException::class);

        $h->client->orderPackages(['21353373']);
    }

    // -- Pickup -------------------------------------------------------------------------------------------------------

    public function testTheProposalsAreAskedForByShipmentAndDate(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/pickup-proposals')]);

        $groups = $h->client->fetchPickupProposals(['21353373'], new \DateTimeImmutable('2026-09-30 15:00'), false, 3);

        self::assertCount(1, $groups);
        self::assertSame('21353373', $groups[0]->packageId);
        self::assertSame('POST ' . self::HOST . '/packages/pickup-date-proposals', $h->sent()[0]);
        self::assertSame(
            ['packages' => [['id' => '21353373']], 'ready_date' => '2026-09-30', 'only_repickup' => false, 'days_ahead' => 3],
            $h->body(),
            'A false is sent; only a null is left out.',
        );
    }

    public function testTheProposalsBodyHasOnlyWhatWasAskedFor(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/pickup-proposals')]);

        $h->client->fetchPickupProposals(['21353373']);

        self::assertSame(['packages' => [['id' => '21353373']]], $h->body());
    }

    public function testAPickupIsBookedWithTheSlotFromTheProposals(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/pickup-proposals'), Fixtures::response('commands/put-accepted'), Fixtures::response('commands/pickup-running'), Fixtures::response('commands/pickup-successful')]);
        $slot = $h->client->fetchPickupProposals(['21353373'])[0]->first();
        self::assertNotNull($slot);

        $result = $h->client->orderPickup(['21353373'], $slot->toPickupDate());

        self::assertTrue($result->isSuccessful());
        self::assertSame('20260929800017', $result->pickupDetails[0]->pickupId);
        $uuid = self::uuidOf($h, 1);
        self::assertSame(
            ['PUT ' . self::HOST . "/pickup-commands/{$uuid}", 'GET ' . self::HOST . "/pickup-commands/{$uuid}", 'GET ' . self::HOST . "/pickup-commands/{$uuid}"],
            array_slice($h->sent(), 1),
        );
        self::assertSame(
            ['packages' => [['id' => '21353373']], 'pickup_date' => ['date' => '2026-09-29', 'min_time' => '09:00', 'max_time' => '17:00', 'hash' => 'e9942106485efca3947053b3a503c33d']],
            $h->body(1),
        );
        self::assertSame([1.0], $h->sleeper->sleeps);
    }

    public function testAPickupCanBeReadLater(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/pickup-successful')]);

        self::assertSame(['21353373'], $h->client->fetchPickupCommand(self::UUID)->successfulPackageIds());
    }

    // -- Cancellation ----------------------------------------------------------------------------------------------------

    public function testACancellationIsSubmittedAndReadUntilItIsDone(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/cancel-queueing'), Fixtures::response('commands/cancel-successful')]);

        $result = $h->client->cancelPackages(['21353373']);

        self::assertTrue($result->isSuccessful());
        self::assertSame(['21353373'], $result->successfulPackageIds());
        $uuid = self::uuidOf($h);
        self::assertSame('PUT ' . self::HOST . "/cancel-command/{$uuid}", $h->sent()[0]);
        self::assertSame('GET ' . self::HOST . "/cancel-command/{$uuid}", $h->sent()[1]);
        self::assertSame(['packages' => [['id' => '21353373']]], $h->body());
    }

    public function testACancellationThatFailsForOneShipmentIsInTheResult(): void
    {
        $body = '{"status":"partial_success","errors":[{"path":"/packages/id/2","message":"Cannot cancel","details":"","code":"cancelError"}],"uuid":"' . self::UUID . '","cancel_command_details":[{"package_id":1,"cancel_success":true,"success_message_type":"success","scheduled_cancel_date":""}]}';
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), FakeTransport::jsonResponse($body)]);

        $result = $h->client->cancelPackages([1, 2]);

        self::assertTrue($result->isPartial());
        self::assertSame(['1'], $result->successfulPackageIds());
        self::assertSame(['2'], $result->failedPackageIds());
    }

    public function testACancellationTheCarrierRefusesIsAResultNotAnException(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/cancel-error')]);

        $result = $h->client->cancelPackages(['21353632']);

        self::assertTrue($result->hasFailed());
        self::assertSame(['21353632'], $result->failedPackageIds());
        self::assertSame('In package tracking found delivery status.', $result->errorsFor('21353632')[0]->message);
    }

    public function testACancellationCanBeReadLaterWithoutWaiting(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/cancel-queueing'), Fixtures::response('commands/cancel-successful')]);

        self::assertFalse($h->client->fetchCancelCommand(self::UUID)->isFinished());
        $result = $h->client->fetchCancelCommand(self::UUID);
        self::assertTrue($result->isSuccessful());
        self::assertSame(['21353373'], $result->successfulPackageIds());
        self::assertSame([], $h->sleeper->sleeps);
        self::assertSame('GET ' . self::HOST . '/cancel-command/' . self::UUID, $h->sent()[0]);
        self::assertCount(2, $h->sent());
    }

    public function testMarkingAsCancelledSendsOneShipment(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->markAsCancelled(21353373);

        self::assertSame('POST ' . self::HOST . '/packages/mark-as-cancelled', $h->sent()[0]);
        self::assertSame(['packages' => [['id' => '21353373']]], $h->body());
    }

    /** @return iterable<string, array{CommandType, string}> */
    public static function abortEndpoints(): iterable
    {
        yield 'generic' => [CommandType::Generic, 'generic-command'];
        yield 'order' => [CommandType::Order, 'order-commands'];
        yield 'pickup' => [CommandType::Pickup, 'pickup-commands'];
        yield 'cancel' => [CommandType::Cancel, 'cancel-command'];
        yield 'documents' => [CommandType::Documents, 'documents-command'];
        yield 'create package' => [CommandType::CreatePackage, 'create-package-command'];
        yield 'csv export' => [CommandType::CsvExport, 'csv-command'];
        yield 'cart check' => [CommandType::Revalidate, 'revalidate-command'];
        yield 'add to tracking' => [CommandType::AddToTracking, 'add-package-to-tracking-command'];
    }

    #[DataProvider('abortEndpoints')]
    public function testACommandIsAbortedThroughTheEndpointOfItsType(CommandType $type, string $segment): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->abortCommand(self::UUID, $type);

        self::assertSame('DELETE ' . self::HOST . "/{$segment}/" . self::UUID, $h->sent()[0]);
    }

    public function testAbortingAFinishedCommandIsARefusal(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-wrong-state', 400)]);

        try {
            $h->client->abortCommand(self::UUID);
            self::fail('Expected a bad request.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('wrongState'));
        }
    }

    // -- Email ---------------------------------------------------------------------------------------------------------------

    public function testTheConfirmationEmailIsSentForOneShipment(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->sendDocumentsEmail(new Package(id: '21353373'));

        self::assertSame('POST ' . self::HOST . '/packages/send-email', $h->sent()[0]);
        self::assertSame(['packages' => [['id' => '21353373']]], $h->body());
    }

    // -- Label --------------------------------------------------------------------------------------------------------------------

    public function testAPdfLabelIsDownloaded(): void
    {
        $h = new ClientHarness([self::pdf()]);

        $label = $h->client->fetchLabel('21353373');

        self::assertTrue($label->isPdf());
        self::assertFalse($label->isText());
        self::assertSame(LabelFileFormat::Pdf, $label->format);
        self::assertSame('application/pdf', $label->contentType);
        self::assertSame(self::PDF, $label->bytes);
        self::assertSame(strlen(self::PDF), $label->size());
        self::assertSame(self::HOST . '/packages/21353373/label', $h->transport->requests[0]->url);
        self::assertSame('GET', $h->transport->requests[0]->method);
    }

    public function testThePageFormatOfAPdfIsAskedForInTheQuery(): void
    {
        $h = new ClientHarness([self::pdf()]);

        $h->client->fetchLabel('21353373', LabelPageFormat::A6);

        self::assertSame(self::HOST . '/packages/21353373/label?label%5Bpage_format%5D=a6', $h->transport->requests[0]->url);
    }

    public function testAZplLabelIsText(): void
    {
        $zpl = "^XA\n^FO50,50^ADN,36,20^FDHello^FS\n^XZ";
        $h = new ClientHarness([new TransportResponse(200, $zpl, ['content-type' => 'text/plain; charset=utf-8'])]);

        $label = $h->client->fetchLabel('21353373');

        self::assertTrue($label->isText());
        self::assertFalse($label->isPdf());
        self::assertSame(LabelFileFormat::Zpl, $label->format);
        self::assertSame($zpl, $label->bytes);
        self::assertSame('text/plain', $label->contentType, 'The media type without its parameters.');
    }

    public function testATextLabelThatIsNotZplHasNoKnownFormat(): void
    {
        $h = new ClientHarness([new TransportResponse(200, 'N\nq812\nA50,50,0,4,1,1,N,"Hello"\nP1', ['content-type' => 'text/plain'])]);

        self::assertNull($h->client->fetchLabel('1')->format, 'EPL cannot be told from other text, so the format stays unknown and the bytes are returned.');
    }

    public function testALabelThatIsNotThereYetIsA204AndAnExceptionYouCanCatch(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        try {
            $h->client->fetchLabel('21353373');
            self::fail('Expected LabelNotReady.');
        } catch (FurgonetkaApiLabelNotReadyException $e) {
            self::assertStringContainsString('yet', $e->getMessage());
        }

        self::assertTrue($h->logger->has(LogLevel::INFO, 'not ready'));
    }

    public function testALabelOfACancelledShipmentIsA404OnThePackageId(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/not-found-label-cancelled', 404)]);

        try {
            $h->client->fetchLabel('21355317');
            self::fail('Expected a not found exception.');
        } catch (FurgonetkaApiNotFoundException $e) {
            self::assertSame(['/package_id'], $e->getFieldPaths());
            self::assertStringContainsString('Package cancelled', $e->getMessage());
        }
    }

    public function testALabelThatCannotBeProducedAnyMoreIsGone(): void
    {
        $h = new ClientHarness([new TransportResponse(410, '{"code":"gone","message":"The label is not available any more","details":""}', ['content-type' => 'application/json'])]);

        $this->expectException(FurgonetkaApiGoneException::class);

        $h->client->fetchLabel('21353373');
    }

    public function testAnEmptyLabelBodyThatIsNotA204IsAParseError(): void
    {
        $h = new ClientHarness([new TransportResponse(200, '', ['content-type' => 'application/pdf'])]);

        $this->expectException(FurgonetkaApiResponseParseException::class);
        $this->expectExceptionMessage('empty body');

        $h->client->fetchLabel('21353373');
    }

    public function testALabelAnswerThatIsNeitherPdfNorTextIsAParseError(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"status":"ok"}')]);

        $this->expectException(FurgonetkaApiResponseParseException::class);
        $this->expectExceptionMessage('not a PDF or a ZPL/EPL text');

        $h->client->fetchLabel('21353373');
    }

    // -- Protocol -------------------------------------------------------------------------------------------------------------------

    public function testTheProtocolIsAPdf(): void
    {
        $h = new ClientHarness([self::pdf()]);

        $file = $h->client->fetchProtocol(['21353373']);

        self::assertTrue($file->isPdf());
        self::assertFalse($file->isZip());
        self::assertSame('application/pdf', $file->contentType);
        self::assertSame('POST ' . self::HOST . '/packages/protocol', $h->sent()[0]);
        self::assertSame(['packages' => [['id' => '21353373']]], $h->body());
    }

    public function testAProtocolThatIsNotReadyIsA204(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $this->expectException(FurgonetkaApiLabelNotReadyException::class);

        $h->client->fetchProtocol(['21353373']);
    }

    public function testAProtocolThatIsNotAPdfIsAParseError(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{}')]);

        $this->expectException(FurgonetkaApiResponseParseException::class);
        $this->expectExceptionMessage('not a PDF');

        $h->client->fetchProtocol(['21353373']);
    }

    public function testAProtocolThatIsAPdfByItsBytesAloneIsAccepted(): void
    {
        $h = new ClientHarness([new TransportResponse(200, self::PDF, ['content-type' => 'application/octet-stream'])]);

        self::assertTrue($h->client->fetchProtocol(['1'])->isPdf());
    }

    // -- Documents ---------------------------------------------------------------------------------------------------------------------

    public function testDocumentsAreRequestedAndTheUrlComesWithTheSuccess(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/documents-queueing'), Fixtures::response('commands/documents-running'), Fixtures::response('commands/documents-successful')]);

        $result = $h->client->requestDocuments(['21353373'], [DocumentType::Labels, DocumentType::PickupConfirmations], LabelOptions::pdf(LabelPageFormat::A4, false));

        self::assertTrue($result->isSuccessful());
        self::assertStringStartsWith('https://c.furgonetka.pl/', (string) $result->url);
        $uuid = self::uuidOf($h);
        self::assertSame('PUT ' . self::HOST . "/documents-command/{$uuid}", $h->sent()[0]);
        self::assertSame(
            ['packages' => [['id' => '21353373']], 'documents_types' => ['labels', 'pickup_confirmations'], 'label' => ['page_format' => 'a4', 'add_cutting_line' => false]],
            $h->body(),
            'The file format is dropped: documents come in the format the labels were ordered in.',
        );
        self::assertSame([1.0, 1.0], $h->sleeper->sleeps);
    }

    public function testADocumentsCommandWithoutLabelOptionsHasNoLabelKey(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $h->client->submitDocuments(['21353373'], [DocumentType::All]);

        self::assertSame(['packages' => [['id' => '21353373']], 'documents_types' => ['all']], $h->body());
    }

    public function testAZplFormatAloneLeavesNoLabelKeyInADocumentsCommand(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $h->client->submitDocuments(['21353373'], [DocumentType::Labels], LabelOptions::zpl());

        self::assertArrayNotHasKey('label', $h->body());
    }

    public function testADocumentsCommandCanBeReadLater(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/documents-running')]);

        self::assertFalse($h->client->fetchDocumentsCommand(self::UUID)->isFinished());
        self::assertSame('GET ' . self::HOST . '/documents-command/' . self::UUID, $h->sent()[0]);
    }

    public function testTheDocumentsFileIsDownloadedWithoutTheToken(): void
    {
        $zip = "PK\x03\x04rest-of-a-zip";
        $h = new ClientHarness([new TransportResponse(200, $zip, ['content-type' => 'application/zip'])]);
        $result = DocumentsCommandResult::fromArray(Fixtures::json('commands/documents-successful'));

        $file = $h->client->downloadDocuments($result);

        self::assertTrue($file->isZip());
        self::assertSame(strlen($zip), $file->size());
        self::assertFalse($file->isPdf());
        self::assertSame('application/zip', $file->contentType);
        self::assertSame($zip, $file->bytes);
        self::assertSame($result->url, $h->transport->requests[0]->url);
        self::assertNull($h->transport->requests[0]->header('Authorization'), 'The file is on another host than the API: the bearer token must not go there.');
        self::assertNull($h->transport->requests[0]->header(FurgonetkaApiConfig::HEADER_COMPANY_ID));
        self::assertSame('GET', $h->transport->requests[0]->method);
    }

    public function testTheDocumentsFileCanBeDownloadedFromAUrlString(): void
    {
        $h = new ClientHarness([self::pdf()]);

        $file = $h->client->downloadDocuments('HTTPS://c.furgonetka.pl/docs/a.pdf?hash=x');

        self::assertTrue($file->isPdf());
        self::assertSame('HTTPS://c.furgonetka.pl/docs/a.pdf?hash=x', $h->transport->requests[0]->url);
    }

    /** @return iterable<string, array{DocumentsCommandResult|string, string}> */
    public static function badDocumentSources(): iterable
    {
        yield 'a command that has no url yet' => [DocumentsCommandResult::fromArray(Fixtures::json('commands/documents-running')), 'no url yet'];
        yield 'an empty string' => ['', 'no url yet'];
        yield 'http' => ['http://c.furgonetka.pl/docs/a.pdf', 'must be https'];
        yield 'a file url' => ['file:///etc/passwd', 'must be https'];
        yield 'a relative path' => ['/docs/a.pdf', 'must be https'];
        yield 'ftp' => ['ftp://c.furgonetka.pl/a.pdf', 'must be https'];
    }

    #[DataProvider('badDocumentSources')]
    public function testOnlyAnHttpsUrlIsDownloaded(DocumentsCommandResult|string $source, string $message): void
    {
        $h = new ClientHarness();

        try {
            $h->client->downloadDocuments($source);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testAnExpiredDocumentsLinkIsAnHttpError(): void
    {
        $h = new ClientHarness([new TransportResponse(403, '<Error><Code>AccessDenied</Code></Error>', ['content-type' => 'application/xml'])]);

        try {
            $h->client->downloadDocuments('https://c.furgonetka.pl/docs/a.zip?expires=1');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiHttpException $e) {
            self::assertSame(403, $e->statusCode);
        }
    }

    public function testAnEmptyDocumentsFileIsAParseError(): void
    {
        $h = new ClientHarness([new TransportResponse(200, '', ['content-type' => 'application/zip'])]);

        $this->expectException(FurgonetkaApiResponseParseException::class);

        $h->client->downloadDocuments('https://c.furgonetka.pl/docs/a.zip');
    }

    public function testANetworkFailureWhileDownloadingIsAnException(): void
    {
        $h = new ClientHarness([new FurgonetkaApiTransportException('SSL certificate problem')]);

        $this->expectException(FurgonetkaApiTransportException::class);

        $h->client->downloadDocuments('https://c.furgonetka.pl/docs/a.zip');
    }
}
