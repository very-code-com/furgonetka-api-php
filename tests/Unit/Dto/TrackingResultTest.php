<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\ParcelTracking;
use VeryCodeCom\FurgonetkaApi\Dto\TrackedPackage;
use VeryCodeCom\FurgonetkaApi\Dto\TrackingEvent;
use VeryCodeCom\FurgonetkaApi\Dto\TrackingResult;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The tracking answers the sandbox gave for a shipment whose states were simulated, for a tracked
 * package and for a starter shipment that has no history at all.
 */
final class TrackingResultTest extends TestCase
{
    private static function tracking(string $fixture): TrackingResult
    {
        return TrackingResult::fromArray(Fixtures::json('tracking/' . $fixture));
    }

    public function testAnOrderedShipmentHasItsParcelAndNoHistoryYet(): void
    {
        $result = self::tracking('ordered');

        self::assertCount(1, $result);
        self::assertSame(['0000888274496Q'], $result->numbers());

        $parcel = $result->parcel('0000888274496Q');
        self::assertNotNull($parcel);
        self::assertSame([], $parcel->events);
        self::assertNull($parcel->latest());
        self::assertNull($parcel->state());
        self::assertFalse($parcel->isDelivered());
        self::assertNull($parcel->pickupTrackingUrl);
        self::assertNull($parcel->deliveryTrackingUrl);
        self::assertSame($parcel, $result->first());
        self::assertFalse($result->isDelivered());
    }

    public function testTheHistoryComesNewestFirstAndTheLatestEventIsTheOneWithTheLatestTime(): void
    {
        $parcel = self::tracking('collected')->first();

        self::assertNotNull($parcel);
        self::assertCount(2, $parcel->events);

        $first = $parcel->events[0];
        self::assertSame(TrackingState::Collected, $first->state);
        self::assertSame('collected', $first->stateValue);
        self::assertSame('Nadanie paczki.', $first->status);
        self::assertSame('2026-09-29T09:14:04+02:00', $first->datetime?->format(\DATE_ATOM));
        self::assertNull($first->branch, 'An empty string in the answer.');
        self::assertNull($first->courierPhone);

        self::assertSame(TrackingState::Ordered, $parcel->events[1]->state);
        self::assertSame($first, $parcel->latest());
        self::assertSame(TrackingState::Collected, $parcel->state());
    }

    public function testEveryStateTheSimulationSetIsRead(): void
    {
        $expected = [
            'collected' => TrackingState::Collected,
            'transit' => TrackingState::Transit,
            'delivery-problem' => TrackingState::DeliveryProblem,
            'delivered' => TrackingState::Delivered,
            'returned' => TrackingState::Returned,
        ];

        foreach ($expected as $fixture => $state) {
            self::assertSame($state, self::tracking($fixture)->first()?->state(), $fixture);
        }
    }

    public function testASimulatedHistoryKeepsTheStatesThatLeadToTheOneThatWasSet(): void
    {
        $parcel = self::tracking('delivery-problem')->first();

        self::assertNotNull($parcel);
        self::assertSame(
            ['delivery-problem', 'delivery', 'transit', 'collected', 'collect-problem', 'ordered'],
            array_map(static fn (TrackingEvent $e): ?string => $e->stateValue, $parcel->events),
        );
        self::assertSame(TrackingState::CollectProblem, $parcel->events[4]->state);
    }

    public function testADeliveredParcelIsDeliveredAndItsShipmentToo(): void
    {
        $result = self::tracking('delivered');

        self::assertTrue($result->first()?->isDelivered());
        self::assertTrue($result->isDelivered());
        self::assertFalse(self::tracking('returned')->isDelivered(), 'Returned to the sender is not delivered.');
    }

    public function testAllDigitWaybillNumbersComeBackAsText(): void
    {
        $result = self::tracking('numeric-number');

        self::assertSame(['1582893168850'], $result->numbers(), 'PHP turns the key into an integer; the number comes back as text.');
        self::assertNotNull($result->parcel('1582893168850'));
        self::assertNull($result->parcel('1582893168851'));

        foreach ($result as $number => $parcel) {
            self::assertSame('1582893168850', $number);
            self::assertSame([], $parcel->events);
        }
    }

    public function testATrackedPackageIsTrackedUnderTheNumberYouGave(): void
    {
        self::assertSame(['Z1234567890L'], self::tracking('tracked')->numbers());
    }

    public function testTheWholeAnswerIsKept(): void
    {
        $data = Fixtures::json('tracking/collected');

        self::assertSame($data, TrackingResult::fromArray($data)->raw());
        self::assertArrayHasKey('additionalInfoTracking', self::tracking('collected')->first()?->raw() ?? []);
    }

    public function testTheLatestEventDoesNotDependOnTheOrderTheyCameIn(): void
    {
        $parcel = ParcelTracking::fromArray(['tracking' => [
            ['state' => 'transit', 'datetime' => '2026-09-29T10:00:00+02:00'],
            ['state' => 'delivered', 'datetime' => '2026-09-29T12:00:00+02:00'],
            ['state' => 'collected', 'datetime' => '2026-09-29T08:00:00+02:00'],
        ]]);

        self::assertSame(TrackingState::Delivered, $parcel->state());
        self::assertTrue($parcel->isDelivered());
    }

    public function testEventsWithoutATimeFallBackToTheFirstOne(): void
    {
        $parcel = ParcelTracking::fromArray(['tracking' => [['state' => 'transit'], ['state' => 'collected']]]);

        self::assertSame(TrackingState::Transit, $parcel->state());
    }

    public function testAStateThatIsNotAMemberReadsAsNullAndKeepsTheRawValue(): void
    {
        $event = TrackingEvent::fromArray(['state' => 'lost-in-space', 'status' => 'x', 'datetime' => 'not a date']);

        self::assertNull($event->state);
        self::assertSame('lost-in-space', $event->stateValue);
        self::assertNull($event->datetime, 'An unreadable time is not worth failing the answer for.');
        self::assertNull(ParcelTracking::fromArray(['tracking' => [['state' => 'lost-in-space']]])->state());
    }

    public function testTheCarrierLinksAreReadFromTheObjectTheSandboxSendsAndFromTheArrayTheSpecificationShows(): void
    {
        $object = ParcelTracking::fromArray(['tracking' => [], 'additionalInfoTracking' => ['pickupTrackingUrl' => 'https://gls.example/p', 'deliveryTrackingUrl' => null]]);
        $list = ParcelTracking::fromArray(['tracking' => [], 'additionalInfoTracking' => [
            ['pickupTrackingUrl' => null, 'deliveryTrackingUrl' => 'https://gls.example/d'],
            ['pickupTrackingUrl' => 'https://gls.example/p2', 'deliveryTrackingUrl' => 'https://gls.example/d2'],
        ]]);

        self::assertSame('https://gls.example/p', $object->pickupTrackingUrl);
        self::assertNull($object->deliveryTrackingUrl);
        self::assertSame('https://gls.example/p2', $list->pickupTrackingUrl);
        self::assertSame('https://gls.example/d', $list->deliveryTrackingUrl, 'The first link that is there wins.');
    }

    public function testAnEmptyAnswerHasNoParcels(): void
    {
        foreach ([TrackingResult::fromArray([]), TrackingResult::fromArray(['parcels' => []])] as $result) {
            self::assertCount(0, $result);
            self::assertSame([], $result->numbers());
            self::assertNull($result->first());
            self::assertFalse($result->isDelivered(), 'No parcel is not "everything delivered".');
        }
    }

    public function testAShipmentIsDeliveredOnlyWhenEveryParcelIs(): void
    {
        $result = TrackingResult::fromArray(['parcels' => [
            'A1' => ['tracking' => [['state' => 'delivered', 'datetime' => '2026-09-29T10:00:00+02:00']]],
            'B2' => ['tracking' => [['state' => 'transit', 'datetime' => '2026-09-29T10:00:00+02:00']]],
        ]]);

        self::assertCount(2, $result);
        self::assertSame(['A1', 'B2'], $result->numbers());
        self::assertFalse($result->isDelivered());
        self::assertTrue($result->parcel('A1')?->isDelivered());
    }

    public function testAddingToTrackingAnswersWithTheIdAndTheCarrier(): void
    {
        $tracked = TrackedPackage::fromArray(Fixtures::json('tracking/add-to-tracking'));

        self::assertSame('21353630', $tracked->id, 'The id comes as a number and is text here.');
        self::assertSame('dpd', $tracked->carrierName);
        self::assertSame(Carrier::Dpd, $tracked->carrier);
        self::assertNull($tracked->name);
        self::assertSame(21353630, $tracked->raw()['package_id']);
    }

    public function testRenamingAnswersWithTheIdAndTheName(): void
    {
        $tracked = TrackedPackage::fromArray(Fixtures::json('tracking/edit-tracked'));

        self::assertSame('21353630', $tracked->id);
        self::assertSame('Renamed probe', $tracked->name);
        self::assertNull($tracked->carrier);
        self::assertNull($tracked->carrierName);
    }

    public function testACarrierThatIsNotAMemberKeepsItsName(): void
    {
        $tracked = TrackedPackage::fromArray(['package_id' => 5, 'service' => 'furgonetka_gielda']);

        self::assertNull($tracked->carrier);
        self::assertSame('furgonetka_gielda', $tracked->carrierName);
    }
}
