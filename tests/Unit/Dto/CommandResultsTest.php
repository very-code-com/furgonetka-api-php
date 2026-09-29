<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Dto\ApiError;
use VeryCodeCom\FurgonetkaApi\Dto\CancelCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\DocumentsCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\OrderCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\PickupCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\PickupProposalGroup;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The results of the four commands, in every state Furgonetka reports. The sandbox gave
 * queueing, running and successful, and one real `error`: cancelling a shipment whose tracking
 * already showed a delivery. `partial_success` and an `error` of an order cannot be provoked there
 * (a carrier has to reject the shipment), so those fixtures come from Furgonetka's documentation.
 */
final class CommandResultsTest extends TestCase
{
    // -- Order: the five statuses ----------------------------------------------------------

    public function testARunningOrderIsNotFinished(): void
    {
        $result = OrderCommandResult::fromArray(Fixtures::json('commands/order-running'));

        self::assertSame(CommandStatus::Running, $result->status);
        self::assertSame('running', $result->statusValue);
        self::assertFalse($result->isFinished());
        self::assertFalse($result->isSuccessful());
        self::assertFalse($result->isPartial());
        self::assertFalse($result->hasFailed());
        self::assertSame([], $result->successfulPackageIds());
        self::assertSame('45b65793-f50d-4edb-b09f-5ff3bcd14abf', $result->uuid);
        self::assertSame('2026-09-29T08:02:58+02:00', $result->datetimeChange?->format(\DATE_ATOM));
    }

    public function testAQueueingCommandIsNotFinished(): void
    {
        $result = CancelCommandResult::fromArray(Fixtures::json('commands/cancel-queueing'));

        self::assertSame(CommandStatus::Queueing, $result->status);
        self::assertFalse($result->isFinished());
        self::assertSame([], $result->details);
    }

    public function testASuccessfulOrderNamesTheShipmentsThatWereOrdered(): void
    {
        $result = OrderCommandResult::fromArray(Fixtures::json('commands/order-successful'));

        self::assertSame(CommandStatus::Successful, $result->status);
        self::assertTrue($result->isFinished());
        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->isPartial());
        self::assertFalse($result->hasFailed());
        self::assertSame([], $result->errors);
        self::assertSame(['21353373'], $result->successfulPackageIds(), 'The ids come as numbers and are strings here.');
        self::assertSame(['21353373'], $result->successfullyOrderedPackages);
        self::assertSame([], $result->failedPackageIds());
    }

    public function testAPartialOrderHasBothTheOrderedAndTheRejectedShipment(): void
    {
        $result = OrderCommandResult::fromArray(Fixtures::json('commands/documented/order-partial-success'));

        self::assertSame(CommandStatus::PartialSuccess, $result->status);
        self::assertTrue($result->isFinished());
        self::assertFalse($result->isSuccessful(), 'Partial is not success: a caller who only checks isSuccessful() must not miss it.');
        self::assertTrue($result->isPartial());
        self::assertFalse($result->hasFailed());
        self::assertSame(['90014858124'], $result->successfulPackageIds());
        self::assertSame(['90014858123'], $result->failedPackageIds());
    }

    public function testAFailedOrderIsAResultNotAnException(): void
    {
        $result = OrderCommandResult::fromArray(Fixtures::json('commands/documented/order-error'));

        self::assertSame(CommandStatus::Error, $result->status);
        self::assertTrue($result->isFinished());
        self::assertTrue($result->hasFailed());
        self::assertFalse($result->isSuccessful());
        self::assertFalse($result->isPartial());
        self::assertSame([], $result->successfulPackageIds());
        self::assertCount(1, $result->errors);
    }

    public function testARealFailedCancellationIsAResultWithAnErrorThatHasNoCode(): void
    {
        $result = CancelCommandResult::fromArray(Fixtures::json('commands/cancel-error'));

        self::assertSame(CommandStatus::Error, $result->status);
        self::assertTrue($result->hasFailed());
        self::assertTrue($result->isFinished());
        self::assertSame([], $result->details);
        self::assertSame([], $result->successfulPackageIds());
        self::assertSame(['21353632'], $result->failedPackageIds());
        self::assertCount(1, $result->errorsFor(21353632));

        $error = $result->errors[0];
        self::assertSame('/packages/id/21353632', $error->path);
        self::assertSame('In package tracking found delivery status.', $error->message);
        self::assertNull($error->code, 'This error came without a code or details: only the path and the message.');
        self::assertNull($error->details);
    }

    // -- Errors are mapped onto shipments ---------------------------------------------------

    public function testAnErrorIsFoundByTheShipmentItsPathNames(): void
    {
        $result = OrderCommandResult::fromArray(Fixtures::json('commands/documented/order-partial-success'));

        $errors = $result->errorsFor('90014858123');

        self::assertCount(1, $errors);
        self::assertSame('carrierValidationError', $errors[0]->code);
        self::assertSame('/packages/id/90014858123', $errors[0]->path);
        self::assertStringContainsString('przewoźnika', (string) $errors[0]->message);
        self::assertStringStartsWith('Tu pojawi się', (string) $errors[0]->details);
        self::assertSame($errors, $result->errorsFor(90014858123), 'An int id is accepted too.');
        self::assertSame([], $result->errorsFor('90014858124'), 'The shipment that was ordered has no error.');
        self::assertSame([], $result->errorsFor('9001485812'), 'A prefix of an id is not the id.');
    }

    public function testErrorsWithoutAShipmentAreGeneralErrors(): void
    {
        $result = OrderCommandResult::fromArray([
            'status' => 'error',
            'errors' => [
                ['path' => '/packages/id/1', 'code' => 'a'],
                ['path' => '/packages/id/2', 'code' => 'b'],
                ['path' => '/packages/id/1', 'code' => 'c'],
                ['message' => 'Regulations were not accepted', 'code' => 'regulations'],
                ['path' => '/order', 'code' => 'd'],
                ['path' => '/packages/id/12/extra', 'code' => 'e'],
                ['path' => "/packages/id/3\n", 'code' => 'f'],
            ],
        ]);

        self::assertSame(['1', '2'], $result->failedPackageIds(), 'Once per shipment, in the order the errors came.');
        self::assertSame(['a', 'c'], array_map(static fn (ApiError $e): ?string => $e->code, $result->errorsFor(1)));
        self::assertSame(['regulations', 'd', 'e', 'f'], array_map(static fn (ApiError $e): ?string => $e->code, $result->generalErrors()));
        self::assertSame([], $result->errorsFor(3), 'A path with a trailing newline is not a shipment path.');
    }

    public function testAStatusThatIsNotAMemberReadsAsNullAndKeepsTheRawValue(): void
    {
        $result = OrderCommandResult::fromArray(['uuid' => 'u', 'status' => 'paused']);

        self::assertNull($result->status);
        self::assertSame('paused', $result->statusValue);
        self::assertFalse($result->isFinished(), 'An unknown status is not final: the runner keeps polling until the timeout.');
        self::assertFalse($result->isSuccessful());
        self::assertFalse($result->hasFailed());
    }

    public function testTheWholeAnswerIsKept(): void
    {
        $data = Fixtures::json('commands/order-successful');

        self::assertSame($data, OrderCommandResult::fromArray($data)->raw());
    }

    // -- Pickup -----------------------------------------------------------------------------

    public function testARunningPickupHasNoDetailsYet(): void
    {
        $result = PickupCommandResult::fromArray(Fixtures::json('commands/pickup-running'));

        self::assertSame(CommandStatus::Running, $result->status);
        self::assertSame([], $result->pickupDetails);
        self::assertSame([], $result->successfulPackageIds());
    }

    public function testASuccessfulPickupHasAnIdAndItsShipments(): void
    {
        $result = PickupCommandResult::fromArray(Fixtures::json('commands/pickup-successful'));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->pickupDetails);
        self::assertSame('20260929800017', $result->pickupDetails[0]->pickupId);
        self::assertSame(['21353373'], $result->pickupDetails[0]->packageIds);
        self::assertSame(['21353373'], $result->successfulPackageIds());
    }

    public function testShipmentsInSeveralPickupsAreListedOnce(): void
    {
        $result = PickupCommandResult::fromArray(['status' => 'successful', 'pickup_details' => [
            ['pickup_id' => 'a', 'package_ids' => [1, 2]],
            ['pickup_id' => 'b', 'package_ids' => [2, 3]],
        ]]);

        self::assertSame(['1', '2', '3'], $result->successfulPackageIds());
    }

    public function testThePickupProposalsOfAShipmentAndTheOneToBook(): void
    {
        $groups = array_map(
            PickupProposalGroup::fromArray(...),
            self::objects(Fixtures::json('commands/pickup-proposals')['packages'] ?? null),
        );

        self::assertCount(1, $groups);
        self::assertSame('21353373', $groups[0]->packageId);
        self::assertCount(6, $groups[0]->proposals);
        self::assertCount(6, $groups[0]->available());

        $first = $groups[0]->first();
        self::assertNotNull($first);
        self::assertSame('2026-09-29', $first->date);
        self::assertSame('09:00', $first->minTime);
        self::assertSame('17:00', $first->maxTime);
        self::assertTrue($first->available);
        self::assertSame('e9942106485efca3947053b3a503c33d', $first->hash);

        self::assertSame(
            ['date' => '2026-09-29', 'min_time' => '09:00', 'max_time' => '17:00', 'hash' => 'e9942106485efca3947053b3a503c33d'],
            $first->toPickupDate()->toArray(),
            'The hash goes back to Furgonetka with the date it was issued for.',
        );
    }

    public function testAProposalThatIsNotAvailableIsSkipped(): void
    {
        $group = PickupProposalGroup::fromArray(['package_id' => 7, 'proposals' => [
            ['date' => '2026-10-01', 'available' => false, 'hash' => 'x'],
            ['date' => '2026-10-02', 'available' => true, 'hash' => 'y'],
        ]]);

        self::assertCount(1, $group->available());
        self::assertSame('y', $group->first()?->hash);
        self::assertNull(PickupProposalGroup::fromArray(['package_id' => 7])->first());
    }

    public function testAProposalWithoutADateCannotBeBooked(): void
    {
        $this->expectException(\LogicException::class);

        \VeryCodeCom\FurgonetkaApi\Dto\PickupProposal::fromArray(['hash' => 'x'])->toPickupDate();
    }

    // -- Cancel -----------------------------------------------------------------------------

    public function testASuccessfulCancellationNamesTheShipment(): void
    {
        $result = CancelCommandResult::fromArray(Fixtures::json('commands/cancel-successful'));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->details);
        self::assertSame('21353373', $result->details[0]->packageId);
        self::assertTrue($result->details[0]->cancelSuccess);
        self::assertSame('success', $result->details[0]->successMessageType);
        self::assertNull($result->details[0]->scheduledCancelDate, 'An empty string in the answer.');
        self::assertSame(['21353373'], $result->successfulPackageIds());
    }

    public function testACancellationThatIsScheduledIsNotCountedAsCancelled(): void
    {
        $result = CancelCommandResult::fromArray(['status' => 'successful', 'cancel_command_details' => [
            ['package_id' => 1, 'cancel_success' => true, 'success_message_type' => 'success'],
            ['package_id' => 2, 'cancel_success' => false, 'success_message_type' => 'scheduled', 'scheduled_cancel_date' => '2026-10-05T00:00:00+02:00'],
        ]]);

        self::assertSame(['1'], $result->successfulPackageIds());
        self::assertSame('2026-10-05', $result->details[1]->scheduledCancelDate?->format('Y-m-d'));
        self::assertFalse($result->details[1]->cancelSuccess);
    }

    // -- Documents --------------------------------------------------------------------------

    public function testTheDocumentsUrlAppearsOnlyWhenTheCommandIsSuccessful(): void
    {
        foreach (['queueing', 'running'] as $state) {
            $result = DocumentsCommandResult::fromArray(Fixtures::json('commands/documents-' . $state));

            self::assertFalse($result->isFinished());
            self::assertNull($result->url);
        }

        $done = DocumentsCommandResult::fromArray(Fixtures::json('commands/documents-successful'));

        self::assertTrue($done->isSuccessful());
        self::assertStringStartsWith('https://c.furgonetka.pl/', (string) $done->url);
        self::assertStringContainsString('&expires=', (string) $done->url, 'The JSON escape & is decoded.');
    }

    // -- Empty and odd bodies ---------------------------------------------------------------

    public function testAnEmptyBodyGivesAnEmptyResult(): void
    {
        foreach ([OrderCommandResult::fromArray([]), PickupCommandResult::fromArray([]), CancelCommandResult::fromArray([]), DocumentsCommandResult::fromArray([])] as $result) {
            self::assertNull($result->uuid);
            self::assertNull($result->status);
            self::assertNull($result->statusValue);
            self::assertNull($result->datetimeChange);
            self::assertSame([], $result->errors);
            self::assertFalse($result->isFinished());
        }
    }

    public function testAnErrorsMapThatThePhpBackendWroteAsAnEmptyListIsNoError(): void
    {
        $result = OrderCommandResult::fromArray(['status' => 'successful', 'errors' => []]);

        self::assertSame([], $result->errors);
        self::assertSame([], $result->generalErrors());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function objects(mixed $value): array
    {
        self::assertIsArray($value);
        $objects = [];

        foreach ($value as $item) {
            self::assertIsArray($item);
            /** @var array<string, mixed> $item */
            $objects[] = $item;
        }

        return $objects;
    }
}
