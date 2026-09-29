<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Dto\CsvExportResult;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\CsvRange;
use VeryCodeCom\FurgonetkaApi\Enum\HsCodeService;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiHttpException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * The helper commands through the client: CSV export, cart check, adding to tracking by command and
 * the HS code search. They all follow the command pattern of ordering (a PUT under a UUID, then reads),
 * which `FurgonetkaApiClientCommandsTest` covers in depth; here is what is particular to each.
 */
final class FurgonetkaApiClientHelpersTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';
    private const UUID = '8b881c52-6473-467a-a7c9-d0aa4c4f3bec';

    /** The uuid the client put at the end of the n-th request's URL. */
    private static function uuidOf(ClientHarness $h, int $index = 0): string
    {
        preg_match('#/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$#D', $h->transport->requests[$index]->url, $match);

        return $match[1] ?? throw new \LogicException('No version 4 UUID at the end of the URL.');
    }

    // -- CSV export ---------------------------------------------------------------------------------------

    public function testACsvExportIsSubmittedAndReadUntilItHasALink(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('csv/queueing'), Fixtures::response('csv/running'), Fixtures::response('csv/successful')]);

        $result = $h->client->exportPackagesCsv(CsvRange::Last30Days);

        self::assertSame(CommandStatus::Successful, $result->status);
        self::assertStringContainsString('.csv?hash=SIGNATURE', (string) $result->url);
        $uuid = self::uuidOf($h);
        self::assertSame(
            ['PUT ' . self::HOST . '/csv-command/' . $uuid, 'GET ' . self::HOST . '/csv-command/' . $uuid, 'GET ' . self::HOST . '/csv-command/' . $uuid, 'GET ' . self::HOST . '/csv-command/' . $uuid],
            $h->sent(),
        );
        self::assertSame(['range' => 'last_30_days'], $h->body(), 'No shipments unless the range is "selected".');
        self::assertSame([1.0, 1.0], $h->sleeper->sleeps);
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
    }

    public function testASelectedExportSendsTheShipmentsAsIdsInAnyForm(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('csv/successful')]);

        $h->client->exportPackagesCsv(CsvRange::Selected, ['21351614', 21351615, new Package(id: '21351616')]);

        self::assertSame(['range' => 'selected', 'packages' => [['id' => '21351614'], ['id' => '21351615'], ['id' => '21351616']]], $h->body());
    }

    public function testASelectedExportWithNoShipmentsIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        try {
            $h->client->exportPackagesCsv(CsvRange::Selected);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('needs the shipments', $e->getMessage());
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testAShipmentIdThatIsNotANumberIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $h->client->submitCsvExport(CsvRange::Selected, ['0000888274496Q']);
        } finally {
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testFurgonetkaRefusingTheShipmentsOfAnExportIsABadRequestOnTheirPath(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-csv-packages-empty', 400)]);

        try {
            $h->client->submitCsvExport(CsvRange::Selected, ['1']);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/packages/0'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('isEmpty'));
        }
    }

    public function testACsvExportCanBeSubmittedAndReadSeparately(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('csv/running')]);

        $uuid = $h->client->submitCsvExport(CsvRange::Today);
        $result = $h->client->fetchCsvExport($uuid);

        self::assertSame(self::uuidOf($h), $uuid);
        self::assertFalse($result->isFinished());
        self::assertSame(['PUT ' . self::HOST . '/csv-command/' . $uuid, 'GET ' . self::HOST . '/csv-command/' . $uuid], $h->sent());
    }

    public function testAnUnknownExportIsA400WithCommandNotExists(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-not-exists', 400)]);

        try {
            $h->client->fetchCsvExport(self::UUID);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'));
        }
    }

    public function testARetryWithTheSameUuidTakesCommandExistsAsAlreadySubmitted(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400), Fixtures::response('csv/successful')]);

        $result = $h->client->exportPackagesCsv(CsvRange::Today, uuid: self::UUID);

        self::assertTrue($result->isSuccessful());
        self::assertSame(['PUT ' . self::HOST . '/csv-command/' . self::UUID, 'GET ' . self::HOST . '/csv-command/' . self::UUID], $h->sent());
        self::assertTrue($h->logger->has(LogLevel::INFO, 'already exists'));
    }

    public function testAnExportThatNeverFinishesEndsInATimeoutThatCarriesTheUuid(): void
    {
        $answers = [Fixtures::response('commands/put-accepted')];

        for ($i = 0; $i < 200; $i++) {
            $answers[] = Fixtures::response('csv/running');
        }

        $h = new ClientHarness($answers);

        try {
            $h->client->exportPackagesCsv(CsvRange::Today);
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid());
        }
    }

    public function testAnExportThatWasAbortedIsReturnedAtOnceNotWaitedOutIntoATimeout(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('csv/queueing'), Fixtures::response('csv/cancelled')]);

        $result = $h->client->exportPackagesCsv(CsvRange::Today);

        self::assertTrue($result->isCancelled());
        self::assertSame(CommandStatus::Cancelled, $result->status);
        self::assertSame([1.0], $h->sleeper->sleeps, 'One wait, for the queueing read; the cancelled read ends it.');
        self::assertCount(3, $h->transport->requests);
    }

    public function testTheCsvFileIsDownloadedWithoutTheTokenFromTheResult(): void
    {
        $csv = "Numer;Status\n0000888274496Q;Doręczona\n";
        $h = new ClientHarness([new TransportResponse(200, $csv, ['content-type' => 'text/csv'])]);
        $result = CsvExportResult::fromArray(Fixtures::json('csv/successful'));

        $file = $h->client->downloadCsvExport($result);

        self::assertSame($csv, $file->bytes);
        self::assertSame('text/csv', $file->contentType);
        self::assertSame($result->url, $h->transport->requests[0]->url);
        self::assertNull($h->transport->requests[0]->header('Authorization'), 'The file is on another host than the API: the bearer token must not go there.');
        self::assertNull($h->transport->requests[0]->header(FurgonetkaApiConfig::HEADER_COMPANY_ID));
        self::assertSame('GET', $h->transport->requests[0]->method);
    }

    public function testTheCsvFileCanBeDownloadedFromAUrlString(): void
    {
        $h = new ClientHarness([new TransportResponse(200, "a;b\n", ['content-type' => 'text/csv'])]);

        $h->client->downloadCsvExport('https://c.furgonetka.pl/export/a.csv?hash=x');

        self::assertSame('https://c.furgonetka.pl/export/a.csv?hash=x', $h->transport->requests[0]->url);
    }

    /** @return iterable<string, array{CsvExportResult|string, string}> */
    public static function badCsvSources(): iterable
    {
        yield 'an export that has no url yet' => [CsvExportResult::fromArray(Fixtures::json('csv/running')), 'The CSV export has no url yet'];
        yield 'an empty string' => ['', 'no url yet'];
        yield 'http' => ['http://c.furgonetka.pl/export/a.csv', 'The CSV export URL must be https'];
        yield 'a file url' => ['file:///etc/passwd', 'must be https'];
    }

    #[DataProvider('badCsvSources')]
    public function testOnlyAnHttpsUrlIsDownloadedAndTheMessageNamesTheCsv(CsvExportResult|string $source, string $message): void
    {
        $h = new ClientHarness();

        try {
            $h->client->downloadCsvExport($source);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testAnExpiredCsvLinkIsAnHttpErrorAndAnEmptyFileIsAParseError(): void
    {
        $h = new ClientHarness([new TransportResponse(403, '<Error><Code>AccessDenied</Code></Error>', ['content-type' => 'application/xml']), new TransportResponse(200, '', ['content-type' => 'text/csv'])]);

        try {
            $h->client->downloadCsvExport('https://c.furgonetka.pl/export/a.csv?expires=1');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiHttpException $e) {
            self::assertSame(403, $e->statusCode);
        }

        try {
            $h->client->downloadCsvExport('https://c.furgonetka.pl/export/a.csv');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiResponseParseException $e) {
            self::assertStringContainsString('empty CSV export file', $e->getMessage());
        }
    }

    // -- Cart check ---------------------------------------------------------------------------------------------

    public function testACartCheckSendsTheShipmentsAndReturnsTheVerdict(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('revalidate/queueing'), Fixtures::response('revalidate/successful')]);

        $result = $h->client->revalidatePackages(['21351614', 21351615]);

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->success);
        $uuid = self::uuidOf($h);
        self::assertSame(
            ['PUT ' . self::HOST . '/revalidate-command/' . $uuid, 'GET ' . self::HOST . '/revalidate-command/' . $uuid, 'GET ' . self::HOST . '/revalidate-command/' . $uuid],
            $h->sent(),
        );
        self::assertSame(['packages' => [['id' => '21351614'], ['id' => '21351615']]], $h->body());
    }

    public function testACartCheckWithNoShipmentsOrAWaybillNumberIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        foreach ([[], ['0000888274496Q'], ['21351614', ' ']] as $ids) {
            try {
                $h->client->revalidatePackages($ids);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testAShipmentThatIsNotYoursIsABadRequestWithPackageOwner(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-revalidate-not-owner', 400)]);

        try {
            $h->client->revalidatePackages(['1']);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('packageOwner'));
            self::assertSame(['/packages/0/id'], $e->getFieldPaths());
        }
    }

    public function testACartCheckCanBeSubmittedAndReadSeparately(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('revalidate/successful')]);

        $uuid = $h->client->submitRevalidate(['1'], self::UUID);

        self::assertSame(self::UUID, $uuid);
        self::assertTrue($h->client->fetchRevalidate($uuid)->success);
        self::assertSame('GET ' . self::HOST . '/revalidate-command/' . self::UUID, $h->sent()[1]);
    }

    // -- Adding to tracking by command ------------------------------------------------------------------------------

    public function testAShipmentIsAddedToTrackingAndTheCommandIsWaitedFor(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('add-to-tracking/queueing'), Fixtures::response('add-to-tracking/running'), Fixtures::response('add-to-tracking/successful')]);

        $result = $h->client->addToTrackingAsync('0000888274496Q', Carrier::Fedex, 'Order 7');

        self::assertTrue($result->isSuccessful());
        self::assertSame('21354258', $result->packageId);
        self::assertSame(Carrier::Fedex, $result->carrier);
        $uuid = self::uuidOf($h);
        self::assertSame('PUT ' . self::HOST . '/add-package-to-tracking-command/' . $uuid, $h->sent()[0]);
        self::assertSame(['package_no' => '0000888274496Q', 'service' => 'fedex', 'name' => 'Order 7'], $h->body());
        self::assertSame([1.0, 1.0], $h->sleeper->sleeps);
    }

    public function testWithoutACarrierFurgonetkaWorksItOut(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('add-to-tracking/successful'), Fixtures::response('commands/put-accepted'), Fixtures::response('add-to-tracking/successful')]);

        $h->client->addToTrackingAsync(' 0000888274496Q ');
        $h->client->addToTrackingAsync('0000888274496Q', '  ', '');

        self::assertSame(['package_no' => '0000888274496Q'], $h->body(0), 'No service, no name: the carrier is worked out.');
        self::assertSame(['package_no' => '0000888274496Q', 'name' => ''], $h->body(2), 'A blank carrier is no carrier; a name is sent as it is.');
    }

    public function testACarrierGivenAsTextIsTakenAsItIs(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $h->client->submitAddToTracking('1', 'newpost');

        self::assertSame('newpost', $h->body()['service'], 'A carrier this release does not list.');
    }

    public function testANumberThatIsAlreadyTrackedIsAPartialSuccessNotAnException(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('add-to-tracking/partial-success-existing')]);

        $result = $h->client->addToTrackingAsync('0000888274496Q');

        self::assertTrue($result->isPartial());
        self::assertSame('21353630', $result->packageId);
        self::assertSame('package_already_exists_in_tracking', $result->errors[0]->code);
    }

    /** @return iterable<string, array{string, Carrier|string|null, string}> */
    public static function badTrackingInput(): iterable
    {
        yield 'no number' => ['', null, 'must not be empty'];
        yield 'a blank number' => ['   ', null, 'must not be empty'];
        yield 'a carrier in capitals' => ['1', 'DPD', 'identifier'];
        yield 'a carrier with a space' => ['1', 'd pd', 'identifier'];
        yield 'a carrier that starts with a digit' => ['1', '1dpd', 'identifier'];
        yield 'a carrier with a newline' => ['1', "dpd\nx", 'identifier'];
    }

    #[DataProvider('badTrackingInput')]
    public function testANumberOrCarrierThatCannotBeRightIsRefusedBeforeARequest(string $number, Carrier|string|null $carrier, string $message): void
    {
        $h = new ClientHarness();

        try {
            $h->client->submitAddToTracking($number, $carrier);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testACarrierFurgonetkaDoesNotKnowIsA400OnTheService(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-tracking-service', 400)]);

        try {
            $h->client->submitAddToTracking('1', 'nosuchcarrier');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/service'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('notInArray'));
        }
    }

    public function testTheTrackingCommandCanBeReadSeparately(): void
    {
        $h = new ClientHarness([Fixtures::response('add-to-tracking/running')]);

        self::assertFalse($h->client->fetchAddToTrackingCommand(self::UUID)->isFinished());
        self::assertSame('GET ' . self::HOST . '/add-package-to-tracking-command/' . self::UUID, $h->sent()[0]);
    }

    // -- HS codes ---------------------------------------------------------------------------------------------------

    public function testAnHsCodeSearchSendsTheTokenAlthoughTheSpecificationListsNone(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('hs-codes/successful')]);

        $result = $h->client->searchHsCodes(HsCodeService::NovaPost, 'shoes');

        self::assertTrue($result->isSuccessful());
        self::assertCount(22, $result->codes());
        $uuid = self::uuidOf($h);
        self::assertSame(['PUT ' . self::HOST . '/packages/duty/hs-codes-autocomplete-command/' . $uuid, 'GET ' . self::HOST . '/packages/duty/hs-codes-autocomplete-command/' . $uuid], $h->sent());
        self::assertSame(['description' => 'shoes', 'service' => 'novapost'], $h->body());
        // The specification lists no security for it, but without a token the sandbox answered 500 from the
        // afternoon of 2026-09-29 on (with one it answers as before), so the token goes with both requests.
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[1]->header('Authorization'));
    }

    public function testACarrierTheAccountMayNotUseIsAnErrorResultNotAnException(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('hs-codes/no-permission')]);

        $result = $h->client->searchHsCodes(HsCodeService::Meest, 'shoes');

        self::assertTrue($result->hasFailed());
        self::assertSame([], $result->codes());
        self::assertSame('Brak uprawnień do danego zasobu API.', $result->errors[0]->message);
    }

    public function testTheCarrierIsTrimmedAndAnyIdentifierGoesThrough(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('commands/put-accepted')]);

        $h->client->submitHsCodeSearch(' novapost ', 'shoes');
        $h->client->submitHsCodeSearch('newpost', 'shoes');

        self::assertSame('novapost', $h->body(0)['service']);
        self::assertSame('newpost', $h->body(1)['service'], 'A carrier this release does not list.');
    }

    /** @return iterable<string, array{string}> */
    public static function badServices(): iterable
    {
        yield 'empty' => [''];
        yield 'capitals' => ['NovaPost'];
        yield 'a space inside' => ['nova post'];
        yield 'a newline inside' => ["nova\npost"];
        yield 'a hyphen' => ['nova-post'];
    }

    #[DataProvider('badServices')]
    public function testACarrierThatIsNotAnIdentifierIsRefusedBeforeARequest(string $service): void
    {
        $h = new ClientHarness();

        try {
            $h->client->submitHsCodeSearch($service, 'shoes');
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('identifier', $e->getMessage());
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testAnUnknownCarrierIsABadRequestOnTheService(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-hs-service', 400)]);

        try {
            $h->client->submitHsCodeSearch('nosuchcarrier', 'shoes');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/service'], $e->getFieldPaths());
        }
    }

    public function testTheSearchCanBeReadSeparatelyAndAnEmptyDescriptionIsSentAsItIs(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('hs-codes/running')]);

        $uuid = $h->client->submitHsCodeSearch(HsCodeService::NovaPost, '');
        $result = $h->client->fetchHsCodeSearch($uuid);

        self::assertSame('', $h->body(0)['description'], 'The sandbox accepted an empty description and searched nothing.');
        self::assertFalse($result->isFinished());
        self::assertSame('GET ' . self::HOST . '/packages/duty/hs-codes-autocomplete-command/' . $uuid, $h->sent()[1]);
    }

    public function testTheClientWithNoTokenCannotSearchAndSendsNothing(): void
    {
        $transport = new FakeTransport([Fixtures::response('commands/put-accepted')]);
        $client = FurgonetkaApiClient::create(\VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory::config(), transport: $transport);

        try {
            $client->searchHsCodes(HsCodeService::NovaPost, 'shoes');
            self::fail('Expected an exception.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('no token source', $e->getMessage());
        }

        self::assertSame([], $transport->requests, 'Without a token the sandbox answers 500, so nothing is sent.');
    }

    public function testTheRealAnswerToASearchWithoutATokenWasAServerError(): void
    {
        // The body the sandbox sent to a search without a token on 2026-09-29 (HTTP 500), for the record.
        $h = new ClientHarness([Fixtures::response('errors/server-error-unhandled', 500)]);

        $this->expectException(FurgonetkaApiServerException::class);

        $h->client->submitHsCodeSearch(HsCodeService::NovaPost, 'shoes');
    }
}
