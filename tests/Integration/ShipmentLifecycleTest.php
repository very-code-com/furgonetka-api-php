<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Dto\LabelOptions;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DocumentType;
use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Enum\PackageState;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageFilters;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\RecordingLogger;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * The shipment lifecycle through the client, against the real sandbox: what the unit tests script,
 * checked for real. It is the flow of the plan (services, validation, price, create, regulations,
 * order, label, documents, protocol, pickup, cancellation), one login for the whole class.
 *
 * It creates, orders and cancels shipments on the sandbox and accepts the DPD regulations of the
 * sandbox account, which is what the plan says the library's own tests do. Set
 * `FURGONETKA_ALLOW_ORDERS=0` to keep a run read-only.
 *
 * The tests are ordered on purpose: each builds on the shipment the one before it left.
 */
final class ShipmentLifecycleTest extends SandboxTestCase
{
    private static ?FurgonetkaApiClient $client = null;
    private static ?RecordingLogger $logger = null;
    private static ?int $serviceId = null;
    private static ?string $packageId = null;
    private static ?string $orderUuid = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$client === null) {
            self::$logger = new RecordingLogger();
            self::$client = $this->sharedClient(self::$logger);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // A shipment that was created and never ordered is still "waiting", and cancelling an ordered
        // one leaves a waiting copy of it behind. Delete those, found by the references this class
        // gives its shipments, so the account does not fill up with them.
        if (self::$client !== null) {
            try {
                $ids = [];

                foreach (self::$client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Waiting)) as $package) {
                    if ($package->id !== null && in_array($package->userReferenceNumber, ['Lifecycle test', 'Lifecycle updated'], true)) {
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
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    /** The shipment an earlier test created, or a skip when that test did not get to it. */
    private static function createdPackageId(): string
    {
        if (self::$packageId === null) {
            self::markTestSkipped('The shipment was not created.');
        }

        return self::$packageId;
    }

    /** The UUID of the order an earlier test placed, or a skip when it did not get to it. */
    private static function placedOrderUuid(): string
    {
        if (self::$orderUuid === null) {
            self::markTestSkipped('The order was not placed.');
        }

        return self::$orderUuid;
    }

    private function requireOrders(): void
    {
        if ($this->env('FURGONETKA_ALLOW_ORDERS') === '0') {
            self::markTestSkipped('FURGONETKA_ALLOW_ORDERS=0 keeps the run read-only.');
        }
    }

    private function request(string $reference): ShipmentRequest
    {
        return ShipmentFactory::request(self::$serviceId ?? 0, reference: $reference);
    }

    public function testTheAccountHasAContractWithDpdAndTheOthersWeNeed(): void
    {
        $services = $this->client()->listServices();

        $dpd = $services->first(Carrier::Dpd);
        self::assertNotNull($dpd, 'The sandbox account has no DPD contract.');
        self::assertSame(AgreementOwner::Furgonetka, $dpd->owner);
        self::assertGreaterThan(0, $dpd->id);
        self::assertGreaterThanOrEqual(10, count($services));
        self::$serviceId = $dpd->id;

        // Furgonetka lists carriers that no enum member has: they read as null, and the name survives.
        foreach ($services as $service) {
            self::assertNotNull($service->carrierName);
        }
    }

    public function testThePublicEndpointsNeedNoLogin(): void
    {
        $anonymous = FurgonetkaApiClient::create($this->config(), null, null, $this->transport());

        $countries = $anonymous->fetchAllowedCountries();
        self::assertTrue($countries->requiresDuty('gb'));
        self::assertFalse($countries->requiresDuty('DE'));
        self::assertContains('GB', $countries->dutyCountryCodes());

        self::assertSame('DPD', $anonymous->fetchDictionary()->carriers()['dpd']);

        $this->expectException(\LogicException::class);
        $anonymous->listServices();
    }

    public function testTheCompaniesOfTheUser(): void
    {
        $companies = $this->client()->listCompanies();

        self::assertNotSame([], $companies);
        self::assertTrue($companies[0]->actual);
        self::assertGreaterThan(0, $companies[0]->id);
    }

    public function testAValidShipmentValidatesAndAnInvalidOneReportsItsFields(): void
    {
        $client = $this->client();
        self::assertNotNull(self::$serviceId);

        self::assertSame([], $client->validateRemotely($this->request('valid')));

        $bad = ShipmentFactory::request(self::$serviceId, receiver: ShipmentFactory::receiver(postcode: '3'));
        $errors = $client->validateRemotely($bad);

        self::assertNotSame([], $errors);
        self::assertSame('/receiver/postcode', $errors[0]->path);
        self::assertSame('format', $errors[0]->code);
    }

    public function testLocalValidationStopsAShipmentThatWouldFailBeforeAnythingIsSent(): void
    {
        $client = $this->client();
        $before = self::$logger->records ?? [];

        try {
            $client->createPackage(ShipmentFactory::request(self::$serviceId ?? 1, receiver: ShipmentFactory::receiver(name: 'A B')));
            self::fail('Expected a FurgonetkaApiValidationException.');
        } catch (FurgonetkaApiValidationException $e) {
            self::assertStringContainsString('/receiver/name', $e->errors[0]);
        }

        self::assertNotNull(self::$logger);
        self::assertCount(count($before) + 1, self::$logger->records, 'Only the validation error is logged: no request was made.');
    }

    public function testPricesComeBackPerCarrierAndTheCheapestSkipsWhatIsUnavailable(): void
    {
        $quotes = $this->client()->calculatePrices($this->request('price'), PriceScope::carriers(Carrier::Dpd, Carrier::InPost, Carrier::Poczta, Carrier::Gls));

        self::assertGreaterThanOrEqual(3, count($quotes));
        $cheapest = $quotes->cheapest();
        self::assertNotNull($cheapest);
        self::assertTrue($cheapest->available);
        self::assertGreaterThan(0, $cheapest->pricing?->priceGross);

        foreach ($quotes->available() as $quote) {
            self::assertLessThanOrEqual($quote->pricing?->priceGross, $cheapest->pricing?->priceGross);
        }

        $dpd = $quotes->forServiceId((int) self::$serviceId);
        self::assertNotNull($dpd);
        self::assertSame('dpd', $dpd->carrierName);
        self::assertNotSame([], $dpd->pricing?->details);
    }

    public function testCreatingAShipmentGivesTheWaitingShipmentBack(): void
    {
        $this->requireOrders();

        $package = $this->client()->createPackage($this->request('Lifecycle test'));
        self::$packageId = $package->id;

        self::assertNotNull($package->id);
        self::assertSame(PackageState::Waiting, $package->state);
        self::assertSame(Carrier::Dpd, $package->carrier);
        self::assertSame(self::$serviceId, $package->serviceId);
        self::assertSame('Lifecycle test', $package->userReferenceNumber);
        $receiver = $package->receiver;
        $pickup = $package->pickup;
        self::assertNotNull($receiver);
        self::assertNotNull($pickup);
        self::assertSame('Anna Nowak', $receiver->name);
        self::assertSame('Kraków', $receiver->city, 'Furgonetka normalises the address.');
        self::assertSame('500600700', $pickup->phone?->number);
        self::assertNotNull($package->pricing?->priceGross);
        self::assertCount(1, $package->parcels);
        self::assertNull($package->parcels[0]->packageNo, 'No waybill before it is ordered.');
    }

    public function testAWaitingShipmentCanBeUpdatedReadListedAndFiltered(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        $updated = $client->updatePackage($id, ShipmentFactory::request((int) self::$serviceId, receiver: ShipmentFactory::receiver(name: 'Zofia Wisniewska'), reference: 'Lifecycle updated'));
        self::assertSame($id, $updated->id);
        self::assertSame('Zofia Wisniewska', $updated->receiver?->name);

        self::assertSame('Lifecycle updated', $client->fetchPackage($id)->userReferenceNumber);

        $waiting = $client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Waiting));
        self::assertGreaterThanOrEqual(1, $waiting->count, 'Only the waiting list reports a count.');
        $ids = array_map(static fn (Package $p): ?string => $p->id, $waiting->packages);
        self::assertContains($id, $ids);

        // Filters and the id filter go through: the shipment is found alone.
        $filtered = $client->listPackages(new PackageListQuery(listType: ListType::All, filters: new PackageFilters(packagesIds: [$id])));
        self::assertCount(1, $filtered);
        self::assertSame($id, $filtered->packages[0]->id);

        // A carrier filter that excludes it.
        $none = $client->listPackages(new PackageListQuery(listType: ListType::Waiting, filters: new PackageFilters(services: [Carrier::Fedex, 'gls'], packagesIds: [$id])));
        self::assertCount(0, $none);

        // Paging: the page after the newest shipment starts with an older one.
        $first = $client->listPackages(new PackageListQuery(limit: 1, listType: ListType::All));
        $second = $client->listPackages(new PackageListQuery(limit: 1, lastPackageId: $first->nextLastPackageId(), listType: ListType::All));
        self::assertNotSame($first->packages[0]->id, $second->packages[0]->id ?? null);
    }

    public function testOrderingNeedsTheRegulationsOfTheCarrierAndTheClientNeverAcceptsThemItself(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        // A FedEx shipment, whose regulations this account has not accepted: 409, with the hint.
        $service = $client->listServices()->first(Carrier::Fedex);
        if ($service !== null) {
            $fedex = $client->createPackage(ShipmentFactory::request((int) $service->id, reference: 'FedEx without regulations'));

            try {
                $pending = $client->pendingRegulations([$fedex]);

                if ($pending !== []) {
                    try {
                        $client->orderPackages([$fedex]);
                        self::fail('Expected a 409.');
                    } catch (FurgonetkaApiConflictException $e) {
                        self::assertTrue($e->hasErrorCode('terms_and_conditions_not_valid'));
                        self::assertStringContainsString('never does that for you', $e->getMessage());
                    }

                    self::assertSame('fedex', $pending[0]->service);
                    self::assertNotSame([], $pending[0]->documents);
                }
            } finally {
                $client->deletePackage($fedex);
            }
        }

        // The DPD ones are accepted explicitly, on this disposable account.
        $pendingDpd = $client->pendingRegulations([$id]);

        if ($pendingDpd !== []) {
            $accepted = $client->acceptRegulations($pendingDpd);
            self::assertNotSame([], $accepted);
        }

        self::assertSame([], $client->pendingRegulations([$id]));
        self::assertSame([], $client->regulationsForPackages([$id]));
    }

    public function testOrderingWaitsForTheResultAndTheShipmentGetsAWaybill(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        $result = $client->orderPackages([$id], LabelOptions::pdf(LabelPageFormat::A6), skipEmail: true);
        self::$orderUuid = $result->uuid;

        self::assertTrue($result->isSuccessful(), json_encode($result->errors, JSON_THROW_ON_ERROR));
        self::assertSame(CommandStatus::Successful, $result->status);
        self::assertSame([$id], $result->successfulPackageIds());
        self::assertSame([], $result->failedPackageIds());
        self::assertNotNull($result->uuid);

        $package = $client->fetchPackage($id);
        self::assertTrue($package->isOrdered());
        self::assertNotSame([], $package->waybillNumbers());
        self::assertTrue($package->pickupAvailable);
        self::assertTrue($package->cancelAvailable);
    }

    public function testTheSameOrderSubmittedAgainWithTheSameUuidIsReadNotPlacedTwice(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();
        $uuid = self::placedOrderUuid();

        // W10: Furgonetka answers 400 "commandExists"; the client takes it as "already submitted".
        self::assertSame($uuid, $client->submitOrder([$id], uuid: $uuid));
        self::assertTrue($client->fetchOrderCommand($uuid)->isSuccessful());

        // Without a UUID of your own there is no retry to be safe about: it is a plain error.
        $this->expectException(FurgonetkaApiBadRequestException::class);
        $client->orderPackages([$id]);
    }

    public function testTheLabelIsAPdfAndTheProtocolIsAPdf(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        $label = $client->fetchLabel($id, LabelPageFormat::A6);
        self::assertTrue($label->isPdf());
        self::assertSame(LabelFileFormat::Pdf, $label->format);
        self::assertStringStartsWith('%PDF-', $label->bytes);
        self::assertGreaterThan(1000, $label->size());

        $a4 = $client->fetchLabel($id);
        self::assertStringStartsWith('%PDF-', $a4->bytes);

        $protocol = $client->fetchProtocol([$id]);
        self::assertTrue($protocol->isPdf());
    }

    public function testDocumentsArePreparedAndDownloadedWithoutAToken(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        $result = $client->requestDocuments([$id], [DocumentType::Labels], LabelOptions::pdf(LabelPageFormat::A6));

        self::assertTrue($result->isSuccessful());
        self::assertNotNull($result->url);
        self::assertStringStartsWith('https://', $result->url);

        $file = $client->downloadDocuments($result);
        self::assertTrue($file->isPdf());
        self::assertSame('application/pdf', $file->contentType);
    }

    public function testPickupProposalsAreBookedAndTheEmailCanBeSentAfterwards(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        $groups = $client->fetchPickupProposals([$id]);

        self::assertCount(1, $groups);
        self::assertSame($id, $groups[0]->packageId);
        $slot = $groups[0]->first();
        self::assertNotNull($slot);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $slot->date);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $slot->hash);

        $pickup = $client->orderPickup([$id], $slot->toPickupDate());
        self::assertTrue($pickup->isSuccessful(), json_encode($pickup->errors, JSON_THROW_ON_ERROR));
        self::assertSame([$id], $pickup->successfulPackageIds());
        self::assertNotNull($pickup->pickupDetails[0]->pickupId);

        $client->sendDocumentsEmail($id);
        $this->addToAssertionCount(1);
    }

    public function testAnOrderedShipmentIsCancelledAndAnAbortOfAFinishedCommandIsRefused(): void
    {
        $this->requireOrders();
        $client = $this->client();
        $id = self::createdPackageId();

        $result = $client->cancelPackages([$id]);

        self::assertTrue($result->isSuccessful(), json_encode($result->errors, JSON_THROW_ON_ERROR));
        self::assertSame([$id], $result->successfulPackageIds());
        self::assertTrue($result->details[0]->cancelSuccess);
        self::assertSame(PackageState::Cancelled, $client->fetchPackage($id)->state);

        // A finished command cannot be aborted (W11, part): 400 "wrongState".
        try {
            $client->abortCommand((string) $result->uuid);
            self::fail('Expected a 400.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('wrongState'));
        }

        self::$packageId = null;
    }

    public function testDeletingWaitingShipmentsOneAndSeveral(): void
    {
        $this->requireOrders();
        $client = $this->client();

        $one = $client->createPackage($this->request('delete one'));
        $client->deletePackage($one);

        // Furgonetka answers 400, not 404, for a shipment that is gone.
        try {
            $client->fetchPackage($one);
            self::fail('Expected a 400.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('packageOwner'));
        }

        $two = [$client->createPackage($this->request('delete two')), $client->createPackage($this->request('delete three'))];
        $client->deletePackages($two);
        $this->addToAssertionCount(1);
    }

    public function testTheRateLimitIsTracked(): void
    {
        $limit = $this->client()->lastRateLimit();

        self::assertNotNull($limit);
        self::assertGreaterThan(0, $limit->limit);
        self::assertLessThanOrEqual($limit->limit, $limit->remaining);
        self::assertNull($limit->retryAfterSeconds);
    }
}
