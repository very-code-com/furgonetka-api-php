<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\CsvRange;
use VeryCodeCom\FurgonetkaApi\Enum\HsCodeService;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * The helper commands against the real sandbox: CSV export with its download, the cart check, adding to
 * tracking by command and the HS code search. Nothing is created that stays: the export is a file on
 * Furgonetka's side, the check and the search only read, and the tracking command is aimed at a number
 * that is tracked already (a tracked package cannot be removed, so no run adds a new one).
 */
final class HelperCommandsTest extends SandboxTestCase
{
    /** The number `TrackingTest` tracks; it is tracked from the first run on. */
    private const TRACKED_NUMBER = 'Z1234567890L';

    private static ?FurgonetkaApiClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    private function starter(): Package
    {
        $starters = $this->starterShipmentsOf($this->client());

        if ($starters === []) {
            self::markTestSkipped('The sandbox account has no starter shipment.');
        }

        return $starters[0];
    }

    // -- CSV export ----------------------------------------------------------------------------------------

    public function testAnExportOfAWholeRangeEndsWithAFileThatCanBeDownloaded(): void
    {
        $result = $this->client()->exportPackagesCsv(CsvRange::Last30Days);

        self::assertTrue($result->isSuccessful(), (string) $result->statusValue);
        self::assertStringStartsWith('https://', (string) $result->url);

        $file = $this->client()->downloadCsvExport($result);

        // The file host serves it as binary/octet-stream, so the content type says nothing about the format.
        self::assertStringStartsWith('"datetime_send","package_no","service"', $file->bytes, 'A quoted CSV with a header row.');
    }

    public function testAnExportOfSelectedShipmentsHoldsThem(): void
    {
        $starter = $this->starter();

        $result = $this->client()->exportPackagesCsv(CsvRange::Selected, [$starter]);

        self::assertTrue($result->isSuccessful());
        $csv = $this->client()->downloadCsvExport($result)->bytes;
        self::assertStringContainsString($starter->waybillNumbers()[0], $csv, 'The waybill number of the selected shipment is in the file.');
    }

    public function testARepeatedSubmissionUnderTheSameUuidIsTakenAsTheSameExport(): void
    {
        $uuid = Uuid::v4();

        $first = $this->client()->exportPackagesCsv(CsvRange::Today, uuid: $uuid);
        $again = $this->client()->exportPackagesCsv(CsvRange::Today, uuid: $uuid);

        self::assertTrue($first->isSuccessful());
        self::assertTrue($again->isSuccessful());
        self::assertSame($first->uuid, $again->uuid);
    }

    public function testAnUnknownExportIsRefused(): void
    {
        try {
            $this->client()->fetchCsvExport('8b881c52-6473-467a-a7c9-d0aa4c4f3bec');
            self::fail('There is no such export.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'));
            self::assertSame(['/uuid'], $e->getFieldPaths());
        }
    }

    // -- Cart check ---------------------------------------------------------------------------------------------

    public function testTheCartCheckOfAShipmentInTheCartFinishes(): void
    {
        $client = $this->client();
        $dpd = $client->listServices()->first(Carrier::Dpd);
        self::assertNotNull($dpd, 'The sandbox account has no DPD contract.');
        $package = $client->createPackage(ShipmentFactory::request($dpd->id ?? 0, reference: 'Helper cart check'));

        try {
            $result = $client->revalidatePackages([$package]);

            self::assertTrue($result->isSuccessful(), (string) $result->statusValue);
            self::assertTrue($result->success, 'A valid shipment passes.');
        } finally {
            $client->deletePackage($package);
        }
    }

    public function testAShipmentThatIsNotInTheCartIsA404OnItsId(): void
    {
        $starter = $this->starter();

        try {
            $this->client()->revalidatePackages([$starter]);
            self::fail('An ordered shipment is not in the cart.');
        } catch (FurgonetkaApiNotFoundException $e) {
            self::assertSame(['/packages/id/' . $starter->id], $e->getFieldPaths());
        }
    }

    public function testAShipmentThatIsNotYoursIsRefusedWithPackageOwner(): void
    {
        try {
            $this->client()->revalidatePackages(['1']);
            self::fail('The shipment is not the account\'s.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('packageOwner'));
            self::assertSame(['/packages/0/id'], $e->getFieldPaths());
        }
    }

    // -- Adding to tracking by command -----------------------------------------------------------------------------

    public function testANumberThatIsTrackedAlreadyIsAPartialSuccessWithTheExistingShipment(): void
    {
        $tracked = null;

        foreach ($this->client()->listPackages(new PackageListQuery(limit: 50, listType: ListType::Tracking)) as $package) {
            if (in_array(self::TRACKED_NUMBER, $package->waybillNumbers(), true)) {
                $tracked = $package;
            }
        }

        if ($tracked === null) {
            self::markTestSkipped(self::TRACKED_NUMBER . ' is not tracked yet: run TrackingTest first (a tracked package cannot be removed, so this class adds none).');
        }

        $result = $this->client()->addToTrackingAsync(self::TRACKED_NUMBER, Carrier::Dpd);

        self::assertTrue($result->isPartial(), (string) $result->statusValue);
        self::assertSame($tracked->id, $result->packageId);
        self::assertSame(Carrier::Dpd, $result->carrier);
        self::assertSame(['package_already_exists_in_tracking'], array_map(static fn ($e): ?string => $e->code, $result->errors));
        self::assertSame('/package_no', $result->errors[0]->path);
    }

    public function testACarrierFurgonetkaDoesNotKnowIsRefusedOnTheService(): void
    {
        try {
            $this->client()->submitAddToTracking(self::TRACKED_NUMBER, 'nosuchcarrier');
            self::fail('Furgonetka must refuse an unknown carrier.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/service'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('notInArray'));
        }
    }

    // -- HS codes --------------------------------------------------------------------------------------------------

    public function testTheHsCodesOfANovaPostSearchAreFound(): void
    {
        $result = $this->client()->searchHsCodes(HsCodeService::NovaPost, 'shoes');

        self::assertTrue($result->isSuccessful(), (string) $result->statusValue);
        self::assertNotSame([], $result->hsCodes);
        self::assertNotSame([], $result->codes());

        foreach ($result->codes() as $code) {
            self::assertMatchesRegularExpression('/^\d{4,10}$/D', $code);
        }
    }

    public function testASearchAtACarrierTheAccountMayNotUseIsAnErrorResult(): void
    {
        $result = $this->client()->searchHsCodes(HsCodeService::Meest, 'shoes');

        self::assertTrue($result->hasFailed(), (string) $result->statusValue);
        self::assertSame([], $result->codes());
        self::assertNotSame([], $result->errors);
    }

    public function testACarrierFurgonetkaDoesNotKnowIsRefusedForTheSearchToo(): void
    {
        try {
            $this->client()->submitHsCodeSearch('nosuchcarrier', 'shoes');
            self::fail('Furgonetka must refuse an unknown carrier.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/service'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('notInArray'));
        }
    }
}
