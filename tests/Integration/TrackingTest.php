<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Dto\MapBounds;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\PointAddress;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Enum\PointFilterType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

/**
 * Tracking, tracked packages, points and the webhook against the real sandbox, on the shipments
 * that came with the account: they are already ordered and carry a waybill number, so nothing is
 * created or ordered here (a cancelled shipment leaves a copy in the waiting list, and this class
 * makes no shipment of its own).
 *
 * The simulation is set on the newest starter DPD shipment, and it changes that shipment: its
 * tracking history becomes the simulated one. The tests end in `delivered`. The oldest starter
 * shipment is only read.
 */
final class TrackingTest extends SandboxTestCase
{
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

    /** @return list<Package> */
    private function starterShipments(): array
    {
        return $this->starterShipmentsOf($this->client());
    }

    private function newestStarter(): Package
    {
        $starters = $this->starterShipments();

        if ($starters === []) {
            self::markTestSkipped('The sandbox account has no starter shipment.');
        }

        return $starters[0];
    }

    private function oldestStarter(): Package
    {
        $starters = $this->starterShipments();

        if ($starters === []) {
            self::markTestSkipped('The sandbox account has no starter shipment.');
        }

        return $starters[array_key_last($starters)];
    }

    private function simulationTarget(): Package
    {
        foreach ($this->starterShipments() as $package) {
            if ($package->carrier === Carrier::Dpd) {
                return $package;
            }
        }

        self::markTestSkipped('The sandbox account has no starter DPD shipment to simulate tracking on.');
    }

    // -- Tracking -----------------------------------------------------------------------------------

    public function testTheTrackingOfAStarterShipmentIsKeyedByItsWaybillNumber(): void
    {
        $oldest = $this->oldestStarter();

        $tracking = $this->client()->fetchTracking($oldest);

        self::assertSame($oldest->waybillNumbers(), $tracking->numbers());
        self::assertNotNull($tracking->first());
    }

    public function testAWaybillNumberIsNotAnIdForTheTrackingEndpoint(): void
    {
        // A waybill with a letter is refused locally, before any request.
        try {
            $this->client()->fetchTracking('0000888274496Q');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not a waybill number', $e->getMessage());
        }

        // A waybill of digits only looks like an id, so the check lets it through and the endpoint refuses it: 404 with the path /package_id.
        foreach ($this->starterShipments() as $package) {
            $waybill = $package->waybillNumbers()[0];

            if (ctype_digit($waybill)) {
                try {
                    $this->client()->fetchTracking($waybill);
                    self::fail('Expected a FurgonetkaApiNotFoundException.');
                } catch (FurgonetkaApiNotFoundException $e) {
                    self::assertSame(['/package_id'], $e->getFieldPaths());
                }

                return;
            }
        }

        self::markTestSkipped('No starter shipment has a waybill number of digits only.');
    }

    public function testASimulatedStateIsTheLatestEventOfTheTracking(): void
    {
        $target = $this->simulationTarget();
        $waybill = $target->waybillNumbers()[0];

        foreach ([TrackingState::Collected, TrackingState::Transit, TrackingState::DeliveryProblem, TrackingState::Delivered] as $state) {
            $this->client()->simulateTracking($target, $state);

            $parcel = $this->client()->fetchTracking($target)->parcel($waybill);

            self::assertNotNull($parcel, $state->value);
            self::assertSame($state, $parcel->state(), $state->value);
            self::assertNotSame([], $parcel->events);
            self::assertNotNull($parcel->latest()?->datetime);
            self::assertNotEmpty($parcel->latest()->status);
            self::assertSame($parcel->latest(), $parcel->events[0], 'Furgonetka lists the newest event first.');
        }

        self::assertTrue($this->client()->fetchTracking($target)->isDelivered());
    }

    public function testASimulationNeedsAShipmentOfTheAccountAndItsRealWaybillNumber(): void
    {
        $target = $this->simulationTarget();

        try {
            $this->client()->simulateTracking('999999999', TrackingState::Transit, 'X1');
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('packageOwner'));
        }

        try {
            $this->client()->simulateTracking($target->id ?? '0', TrackingState::Transit, '0000000000000');
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notExists'));
        }
    }

    // -- Tracked packages -----------------------------------------------------------------------------

    public function testAPackageIsTrackedRenamedAndFoundInTheTrackingList(): void
    {
        $number = 'Z1234567890L';

        try {
            $added = $this->client()->addToTracking($number, Carrier::Dpd, 'Tracked by the tests');
            self::assertSame(Carrier::Dpd, $added->carrier);
        } catch (FurgonetkaApiBadRequestException $e) {
            // A tracked package cannot be removed, so from the second run on the number is there already.
            self::assertTrue($e->hasErrorCode('exists'), $e->getMessage());
        }

        $tracked = null;
        foreach ($this->client()->listPackages(new PackageListQuery(limit: 50, listType: ListType::Tracking)) as $package) {
            if (in_array($number, $package->waybillNumbers(), true)) {
                $tracked = $package;
            }
        }

        self::assertNotNull($tracked, 'The tracking list holds the tracked package.');
        self::assertSame([$number], $this->client()->fetchTracking($tracked)->numbers());

        $renamed = $this->client()->renameTrackedPackage($tracked, 'Renamed by the tests');

        self::assertSame($tracked->id, $renamed->id);
        self::assertSame('Renamed by the tests', $renamed->name);
    }

    public function testAWaybillNumberOfAnOrderedShipmentCannotBeTracked(): void
    {
        $waybill = $this->newestStarter()->waybillNumbers()[0];

        try {
            $this->client()->addToTracking($waybill);
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('existsSent'), $e->getMessage());
        }
    }

    // -- Points ----------------------------------------------------------------------------------------

    public function testPointsAreFoundByPhraseCoordinatesAddressAndIdentifier(): void
    {
        $client = $this->client();
        $services = [PointService::Inpost, PointService::Dpd];

        $byPhrase = $client->findPoints(new PointsQuery($services, searchPhrase: '00-026 Warszawa', limit: 5));
        self::assertGreaterThan(0, count($byPhrase));
        self::assertLessThanOrEqual(5, count($byPhrase));

        foreach ($byPhrase as $point) {
            self::assertNotNull($point->identifier());
            self::assertContains($point->serviceValue, ['inpost', 'dpd']);
            self::assertNotNull($point->address?->city);
            self::assertNotNull($point->coordinates?->latitude);
        }

        $centre = new Coordinates(52.2297, 21.0122);
        $byCoordinates = $client->findPoints(new PointsQuery($services, coordinates: $centre, maxDistanceKm: 3.0, limit: 10));
        self::assertGreaterThan(0, count($byCoordinates));
        $distances = array_values(array_filter(array_map(static fn ($p): ?float => $p->distance, $byCoordinates->points), static fn (?float $d): bool => $d !== null));
        $sorted = $distances;
        sort($sorted);
        self::assertSame($sorted, $distances, 'The points come nearest first.');
        self::assertLessThanOrEqual(3.0, $distances === [] ? 0.0 : max($distances));

        $byAddress = $client->findPoints(new PointsQuery([PointService::Inpost], address: new PointAddress(postcode: '31-021', street: 'Floriańska 15', city: 'Kraków', countryCode: 'PL'), limit: 3));
        self::assertGreaterThan(0, count($byAddress));
        self::assertEqualsWithDelta(50.06, $byAddress->coordinates?->latitude, 0.05, 'The search was centred on Kraków.');

        $first = $byPhrase->first();
        self::assertNotNull($first);
        $identifier = $first->identifier() ?? '';
        $byId = $client->findPoints(PointsQuery::forPoint($identifier, [$first->service ?? $first->serviceValue ?? 'dpd']));
        self::assertSame($identifier, $byId->first()?->identifier());
        self::assertSame($identifier, $byId->searchedPointId);
    }

    public function testThePointFiltersNarrowTheResult(): void
    {
        $client = $this->client();
        $search = static fn (PointFilterType $type): PointsQuery => new PointsQuery(
            [PointService::Inpost, PointService::Dpd],
            searchPhrase: '00-026 Warszawa',
            pointTypes: [$type],
            limit: 20,
        );

        $cod = $client->findPoints($search(PointFilterType::CodOnly));
        self::assertGreaterThan(0, count($cod));
        foreach ($cod as $point) {
            self::assertTrue($point->cod, (string) $point->identifier());
        }

        $food = $client->findPoints($search(PointFilterType::Food));
        foreach ($food as $point) {
            self::assertTrue($point->food, (string) $point->identifier());
        }

        $lockers = $client->findPoints(new PointsQuery([PointService::Inpost], searchPhrase: '00-026 Warszawa', type: PointServiceType::ParcelMachine, limit: 5));
        self::assertGreaterThan(0, count($lockers));
        foreach ($lockers as $point) {
            self::assertSame(PointServiceType::ParcelMachine, $point->serviceType);
        }
    }

    public function testPointsInsideAPartOfTheMap(): void
    {
        $bounds = MapBounds::box(52.24, 21.00, 52.22, 21.03);

        $points = $this->client()->findPoints(new PointsQuery([PointService::Inpost], coordinates: new Coordinates(52.2297, 21.0122), mapBounds: $bounds, limit: 10));

        self::assertGreaterThan(0, count($points));
        foreach ($points as $point) {
            $at = $point->coordinates;
            self::assertNotNull($at);
            self::assertEqualsWithDelta(52.23, $at->latitude, 0.011, (string) $point->identifier());
            self::assertEqualsWithDelta(21.015, $at->longitude, 0.016, (string) $point->identifier());
        }
    }

    // -- Webhook -----------------------------------------------------------------------------------------

    public function testTheWebhookSettingsAndTheLogAreReadWhateverTheAccountHas(): void
    {
        $settings = $this->client()->fetchWebhookSettings();

        self::assertSame($settings->url !== null, $settings->isConfigured());
        if (!$settings->isConfigured()) {
            self::assertNull($settings->token);
        }

        $page = $this->client()->listWebhookLogs();

        self::assertSame($page->isNextPage && $page->logs !== [], $page->nextCursor() !== null);
        foreach ($page as $log) {
            self::assertNotNull($log->id);
        }
    }
}
