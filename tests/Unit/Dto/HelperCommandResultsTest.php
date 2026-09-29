<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Dto\AddToTrackingCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\CsvExportResult;
use VeryCodeCom\FurgonetkaApi\Dto\HsCode;
use VeryCodeCom\FurgonetkaApi\Dto\HsCodeSearchResult;
use VeryCodeCom\FurgonetkaApi\Dto\RevalidateResult;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookCommandResult;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The answers of the helper commands: CSV export, cart check, adding to tracking, HS code search and
 * the webhook check. The bodies are real, from the sandbox, except where a test says otherwise.
 */
final class HelperCommandResultsTest extends TestCase
{
    // -- CSV export -----------------------------------------------------------------------------------

    public function testACsvExportInTheQueueHasNoLinkYet(): void
    {
        $result = CsvExportResult::fromArray(Fixtures::json('csv/queueing'));

        self::assertSame(CommandStatus::Queueing, $result->status);
        self::assertFalse($result->isFinished());
        self::assertNull($result->url);
        self::assertSame('2798bf11-5dc5-41db-af21-ddbb282f6bb5', $result->uuid);
        self::assertSame('2026-09-29T10:51:37+02:00', $result->datetimeChange?->format('c'));
    }

    public function testAnExportAbortedBeforeItStartedIsFinishedAndNeitherSuccessfulNorFailed(): void
    {
        $result = CsvExportResult::fromArray(Fixtures::json('csv/cancelled'));

        self::assertSame(CommandStatus::Cancelled, $result->status);
        self::assertTrue($result->isCancelled());
        self::assertTrue($result->isFinished());
        self::assertFalse($result->isSuccessful());
        self::assertFalse($result->isPartial());
        self::assertFalse($result->hasFailed());
        self::assertNull($result->url);
        self::assertSame([], $result->errors);
    }

    public function testOnlyTheCancelledStatusIsCancelled(): void
    {
        foreach (['csv/queueing', 'csv/running', 'csv/successful'] as $name) {
            self::assertFalse(CsvExportResult::fromArray(Fixtures::json($name))->isCancelled(), $name);
        }
    }

    public function testARunningCsvExportHasNoLinkYetEither(): void
    {
        $result = CsvExportResult::fromArray(Fixtures::json('csv/running'));

        self::assertSame(CommandStatus::Running, $result->status);
        self::assertNull($result->url);
    }

    public function testAFinishedCsvExportHasASignedLink(): void
    {
        $result = CsvExportResult::fromArray(Fixtures::json('csv/successful'));

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->isFinished());
        self::assertSame([], $result->errors);
        self::assertStringStartsWith('https://c.furgonetka.pl/test.furgonetka.pl/export/675316/', (string) $result->url);
        self::assertStringEndsWith('.csv?hash=SIGNATURE&expires=1790672497', (string) $result->url);
    }

    // -- Cart check -------------------------------------------------------------------------------------

    public function testACartCheckSaysWhetherTheShipmentsPass(): void
    {
        $result = RevalidateResult::fromArray(Fixtures::json('revalidate/successful'));

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->success);
        self::assertSame('1c59459b-c18d-40cc-8a7b-c560479c7211', $result->uuid);
        self::assertNull(RevalidateResult::fromArray(Fixtures::json('revalidate/queueing'))->success, 'Not known before the check is done.');
    }

    public function testACartCheckThatFailsIsAResultWithSuccessFalse(): void
    {
        // The shape is from the specification; the sandbox only ever passed the shipments it was given.
        $result = RevalidateResult::fromArray(['status' => 'successful', 'errors' => [], 'uuid' => 'x', 'success' => false]);

        self::assertTrue($result->isSuccessful(), 'The command worked.');
        self::assertFalse($result->success, 'The shipments did not pass.');
    }

    // -- Adding to tracking ----------------------------------------------------------------------------------

    public function testAShipmentAddedToTrackingComesBackWithItsIdAndCarrier(): void
    {
        $result = AddToTrackingCommandResult::fromArray(Fixtures::json('add-to-tracking/successful'));

        self::assertTrue($result->isSuccessful());
        self::assertSame('21354258', $result->packageId, 'A number in the answer, text here, like every shipment id.');
        self::assertSame(Carrier::Fedex, $result->carrier);
        self::assertSame('fedex', $result->carrierName);
    }

    public function testWhileItRunsTheShipmentIsNotKnownYet(): void
    {
        foreach (['add-to-tracking/queueing', 'add-to-tracking/running'] as $name) {
            $result = AddToTrackingCommandResult::fromArray(Fixtures::json($name));

            self::assertFalse($result->isFinished());
            self::assertNull($result->packageId);
            self::assertNull($result->carrier);
            self::assertNull($result->carrierName);
        }
    }

    public function testANumberThatIsAlreadyTrackedIsAPartialSuccessWithTheExistingShipment(): void
    {
        $result = AddToTrackingCommandResult::fromArray(Fixtures::json('add-to-tracking/partial-success-existing'));

        self::assertTrue($result->isPartial());
        self::assertFalse($result->isSuccessful());
        self::assertSame('21353630', $result->packageId, 'The answer names the shipment that is already there.');
        self::assertSame(Carrier::Dpd, $result->carrier);
        self::assertSame(['package_already_exists_in_tracking'], array_map(static fn ($e): ?string => $e->code, $result->errors));
        self::assertSame('/package_no', $result->errors[0]->path);
        self::assertCount(1, $result->generalErrors());
    }

    public function testACarrierThisReleaseDoesNotKnowKeepsItsName(): void
    {
        $result = AddToTrackingCommandResult::fromArray(['status' => 'successful', 'errors' => [], 'uuid' => 'x', 'package_id' => 7, 'service' => 'newpost']);

        self::assertNull($result->carrier);
        self::assertSame('newpost', $result->carrierName);
    }

    // -- HS codes --------------------------------------------------------------------------------------

    public function testTheHsCodesOfASearch(): void
    {
        $result = HsCodeSearchResult::fromArray(Fixtures::json('hs-codes/successful'));

        self::assertTrue($result->isSuccessful());
        self::assertCount(52, $result->hsCodes);
        self::assertSame('61159610', $result->hsCodes[0]->hsCode);
        self::assertSame('Golf shoes synthetic hand knitting', $result->hsCodes[0]->description);
        self::assertSame('en', $result->hsCodes[0]->lang);
    }

    public function testTheSameCodeComesWithSeveralDescriptionsAndIsListedOnce(): void
    {
        $result = HsCodeSearchResult::fromArray(Fixtures::json('hs-codes/successful'));
        $codes = $result->codes();

        self::assertCount(22, $codes, '52 entries are 22 different codes.');
        self::assertSame($codes, array_values(array_unique($codes)));
        self::assertSame('61159610', $codes[0], 'In the order they came.');
        self::assertContains('64029910', $codes);
    }

    public function testASearchTheAccountMayNotMakeIsAnErrorResultWithNoCodes(): void
    {
        $result = HsCodeSearchResult::fromArray(Fixtures::json('hs-codes/no-permission'));

        self::assertTrue($result->hasFailed());
        self::assertSame([], $result->hsCodes);
        self::assertSame([], $result->codes());
        self::assertNull($result->errors[0]->path);
        self::assertNull($result->errors[0]->code, 'The sandbox sent an empty code for this refusal.');
        self::assertSame('Brak uprawnień do danego zasobu API.', $result->errors[0]->message);
    }

    public function testASearchStillRunningHasAnEmptyList(): void
    {
        $result = HsCodeSearchResult::fromArray(Fixtures::json('hs-codes/running'));

        self::assertSame(CommandStatus::Running, $result->status);
        self::assertSame([], $result->hsCodes);
    }

    public function testAnEntryWithoutACodeIsSkippedByCodes(): void
    {
        $result = HsCodeSearchResult::fromArray(['status' => 'successful', 'errors' => [], 'uuid' => 'x', 'result' => ['hsCodes' => [['description' => 'No code'], ['hsCode' => '1', 'description' => 'One']]]]);

        self::assertCount(2, $result->hsCodes);
        self::assertSame(['1'], $result->codes());
        self::assertEquals(new HsCode(null, 'No code', null), $result->hsCodes[0]);
    }

    public function testAnAnswerWithoutAResultHasNoCodes(): void
    {
        self::assertSame([], HsCodeSearchResult::fromArray(['status' => 'queueing', 'errors' => [], 'uuid' => 'x'])->hsCodes);
    }

    // -- The webhook check -------------------------------------------------------------------------------

    public function testAWebhookCheckThatCouldNotReachTheAddressEndsAsAnErrorWithNothingListed(): void
    {
        $result = WebhookCommandResult::fromArray(Fixtures::json('webhook/check-error'));

        self::assertTrue($result->hasFailed());
        self::assertSame([], $result->errors, 'Nothing says why: the reply of the receiver is the only clue.');
        self::assertSame('df392e29-99e0-4320-97ac-4096da995d34', $result->uuid);
    }

    public function testAWebhookCheckThatWorkedIsSuccessful(): void
    {
        // The shape of any command answer. The sandbox showed this status for an address that answered
        // {"status":"OK"} with a 200, but that body was not kept, so it is built here.
        $result = WebhookCommandResult::fromArray(['status' => 'successful', 'datetime_change' => '2026-09-29T11:15:52+02:00', 'errors' => [], 'uuid' => 'x']);

        self::assertTrue($result->isSuccessful());
    }

    public function testAnUnknownStatusIsNotFinalAndKeepsItsRawValue(): void
    {
        $result = CsvExportResult::fromArray(['status' => 'paused', 'errors' => [], 'uuid' => 'x']);

        self::assertNull($result->status);
        self::assertSame('paused', $result->statusValue);
        self::assertFalse($result->isFinished());
    }

    public function testTheWholeAnswerIsKept(): void
    {
        $data = Fixtures::json('csv/successful');

        self::assertSame($data, CsvExportResult::fromArray($data)->raw());
        self::assertSame(Fixtures::json('hs-codes/successful'), HsCodeSearchResult::fromArray(Fixtures::json('hs-codes/successful'))->raw());
    }
}
