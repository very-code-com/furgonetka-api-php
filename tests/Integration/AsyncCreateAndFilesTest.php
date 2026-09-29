<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\SendToMeRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Enum\PackageState;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * Creating a shipment as a command, uploading files and the send-to-me link, against the real
 * sandbox. Shipments made here are created and never ordered, so they only leave a "waiting" copy,
 * which the class deletes at the end by the references it gave them.
 */
final class AsyncCreateAndFilesTest extends SandboxTestCase
{
    private const REFERENCES = ['Async create test', 'Async retry test', 'Async refused test'];
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAAIAAgBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private static ?FurgonetkaApiClient $client = null;
    private static ?int $serviceId = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
        self::$serviceId ??= self::$client->listServices()->first(Carrier::Dpd)?->id;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$client === null) {
            return;
        }

        try {
            $ids = [];

            foreach (self::$client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Waiting)) as $package) {
                if ($package->id !== null && in_array($package->userReferenceNumber, self::REFERENCES, true)) {
                    $ids[] = $package->id;
                }
            }

            if ($ids !== []) {
                self::$client->deletePackages($ids);
            }
        } catch (\Throwable) {
            // Best effort: the sandbox is disposable.
        }
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    private function serviceId(): int
    {
        if (self::$serviceId === null) {
            self::markTestSkipped('The sandbox account has no DPD contract.');
        }

        return self::$serviceId;
    }

    // -- Creating a shipment as a command ---------------------------------------------------------------

    public function testAShipmentIsCreatedThroughACommand(): void
    {
        $result = $this->client()->createPackageAsync(ShipmentFactory::request($this->serviceId(), reference: 'Async create test'));

        self::assertTrue($result->isSuccessful(), implode('; ', array_map('strval', $result->errors)));
        self::assertNotNull($result->packageId);
        self::assertFalse($result->needsRedirect());

        $package = $this->client()->fetchPackage($result->packageId);

        self::assertSame(PackageState::Waiting, $package->state);
        self::assertSame('Async create test', $package->userReferenceNumber);
    }

    public function testARetryWithTheSameUuidCreatesOneShipmentOnly(): void
    {
        $uuid = Uuid::v4();
        $request = ShipmentFactory::request($this->serviceId(), reference: 'Async retry test');

        $first = $this->client()->createPackageAsync($request, $uuid);
        $second = $this->client()->createPackageAsync($request, $uuid);

        self::assertTrue($first->isSuccessful());
        self::assertSame($first->packageId, $second->packageId, 'The second call read the command instead of making another shipment.');

        $same = array_filter(
            $this->client()->listPackages(new PackageListQuery(limit: 50, listType: ListType::Waiting))->packages,
            static fn ($p): bool => $p->userReferenceNumber === 'Async retry test',
        );

        self::assertCount(1, $same);
    }

    public function testAShipmentFurgonetkaRefusesEndsInAFailedResult(): void
    {
        // Passes the local rules; the carrier does not deliver to this postcode.
        $request = ShipmentFactory::request($this->serviceId(), receiver: ShipmentFactory::receiver(postcode: '99-999', city: 'Nowhere'), reference: 'Async refused test');

        $result = $this->client()->createPackageAsync($request);

        self::assertTrue($result->hasFailed());
        self::assertNull($result->packageId);
        self::assertNotSame([], $result->errors);
        self::assertContains('/0/postcode', array_map(static fn ($e): ?string => $e->path, $result->errors), 'The path is indexed: /0 is the shipment.');
    }

    public function testACommandTheAccountDoesNotKnowIsA400(): void
    {
        try {
            $this->client()->fetchCreatePackageCommand(Uuid::v4());
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'));
        }
    }

    // -- Files ------------------------------------------------------------------------------------------------

    public function testAFileIsUploadedAndItsStatusIsReadBothWays(): void
    {
        $result = $this->client()->uploadFile('invoice.pdf', self::PDF, 'invoice');

        self::assertTrue($result->isSuccessful(), implode('; ', array_map('strval', $result->errors)));
        self::assertNotNull($result->uuid);
        self::assertNotNull($result->fileUuid);
        self::assertNotSame($result->uuid, $result->fileUuid);

        $again = $this->client()->fetchFileUpload($result->uuid);

        self::assertSame($result->fileUuid, $again->fileUuid, 'Reading it later gives the same file.');
    }

    public function testAPhotoIsTakenByItsFileUuidAndNotByTheUuidOfTheCommand(): void
    {
        // The panel uploads a shipment photo with the type `packagePhoto` and hands the shipment the `file_uuid`
        // (seen in its requests). Without that type, no UUID was ever accepted for `photos`.
        $upload = $this->client()->uploadFile('photo.jpg', (string) base64_decode(self::JPEG_BASE64, true), 'packagePhoto');

        self::assertTrue($upload->isSuccessful(), implode('; ', array_map('strval', $upload->errors)));
        self::assertNotNull($upload->uuid);
        self::assertNotNull($upload->fileUuid);

        $withFile = $this->client()->validateRemotely(ShipmentFactory::request($this->serviceId(), photos: [$upload->fileUuid]));
        $withCommand = $this->client()->validateRemotely(ShipmentFactory::request($this->serviceId(), photos: [$upload->uuid]));

        self::assertSame([], $withFile, 'The file UUID is what a shipment takes.');
        self::assertCount(1, $withCommand);
        self::assertSame('/photos', $withCommand[0]->path);
        self::assertSame('noFileIncluded', $withCommand[0]->code, 'The UUID of the command is refused like an unknown one.');
    }

    public function testSeveralUploadsAreReadInOneCall(): void
    {
        $one = $this->client()->uploadFile('a.pdf', self::PDF);
        $two = $this->client()->uploadFile('b.txt', 'plain text', 'other');

        $results = $this->client()->fetchFileUploads([(string) $one->uuid, (string) $two->uuid]);

        self::assertCount(2, $results);
        self::assertSame($one->fileUuid, $results[(string) $one->uuid]->fileUuid);
        self::assertSame($two->fileUuid, $results[(string) $two->uuid]->fileUuid);
    }

    public function testAnUploadTheAccountDoesNotKnowFailsTheWholeRead(): void
    {
        $known = $this->client()->uploadFile('a.pdf', self::PDF);

        try {
            $this->client()->fetchFileUploads([(string) $known->uuid, Uuid::v4()]);
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('valueTypeNotValid'));
            self::assertSame(['/uuid'], $e->getFieldPaths());
        }
    }

    public function testARetryOfAnUploadWithTheSameUuidStoresTheFileOnce(): void
    {
        $uuid = Uuid::v4();

        $first = $this->client()->uploadFile('a.pdf', self::PDF, uuid: $uuid);
        $second = $this->client()->uploadFile('a.pdf', self::PDF, uuid: $uuid);

        self::assertSame($first->fileUuid, $second->fileUuid);
    }

    // -- Send to me ----------------------------------------------------------------------------------------------

    public function testTheSendToMeLinkIsBuiltForAnAddressAndForAPoint(): void
    {
        $address = $this->client()->createSendToMeLink(new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan Kowalski', street: 'Jasna 10/12', postcode: '00-001', city: 'Warszawa'));
        $point = $this->client()->createSendToMeLink(new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan Kowalski', point: 'WAW099', service: PointService::Inpost));

        foreach ([$address, $point] as $link) {
            self::assertStringStartsWith('https://sandbox.furgonetka.pl/konto/dodawanie-paczki?sendToMeData=', (string) $link->url);
        }

        self::assertNotSame($address->url, $point->url);
    }

    public function testDataFurgonetkaRefusesIsA400WithFieldErrors(): void
    {
        try {
            $this->client()->createSendToMeLink(new SendToMeRequest('not-an-email', '1', 'PL', name: 'Jan', street: 'Jasna 1', postcode: '00-001', city: 'Warszawa'));
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertContains('/receiver/email', $e->getFieldPaths());
            self::assertContains('/receiver/phone', $e->getFieldPaths());
        }
    }
}
