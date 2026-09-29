<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Dto\MapBounds;
use VeryCodeCom\FurgonetkaApi\Dto\OpeningHours;
use VeryCodeCom\FurgonetkaApi\Dto\Point;
use VeryCodeCom\FurgonetkaApi\Dto\PointAddress;
use VeryCodeCom\FurgonetkaApi\Dto\PointList;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * Points as the sandbox answered five searches: by phrase, by coordinates, by address, by
 * identifier and by a phrase it could not place. The specification says `point_id`; the sandbox
 * never sends it and identifies a point by `code`.
 */
final class PointsTest extends TestCase
{
    private static function list(string $fixture): PointList
    {
        return PointList::fromArray(Fixtures::json('points/' . $fixture));
    }

    public function testAPointOfAPhraseSearch(): void
    {
        $list = self::list('phrase');

        self::assertCount(2, $list);
        $point = $list->points[0];

        self::assertSame('PL24557', $point->code);
        self::assertNull($point->pointId, 'The sandbox does not send point_id.');
        self::assertSame('PL24557', $point->identifier());
        self::assertSame('DPD Pickup - Punkt Sieci Własnej', $point->name);
        self::assertSame('Punkt Sieci Własnej', $point->originalName);
        self::assertTrue($point->active);
        self::assertSame(PointService::Dpd, $point->service);
        self::assertSame('dpd', $point->serviceValue);
        self::assertSame(PointServiceType::ServicePoint, $point->serviceType);
        self::assertSame('service_point', $point->serviceTypeValue);
        self::assertSame('dpd_standard', $point->type);
        self::assertSame('DPD Pickup', $point->pointTypeName);
        self::assertTrue($point->isSendPoint);
        self::assertTrue($point->isDeliveryPoint);
        self::assertFalse($point->cod);
        self::assertTrue($point->digitalLabel);
        self::assertFalse($point->food);
        self::assertFalse($point->holiday);
        self::assertFalse($point->furgonetkaPoint);
        self::assertNull($point->maxSupportedWeight);
        self::assertNull($point->distance, 'The sandbox left the distance null on the first point of a phrase search.');
        self::assertGreaterThan(0.0, $list->points[1]->distance);
        self::assertSame([], $point->photos);
        self::assertNull($point->boxes);
        self::assertNull($point->holidayStart);
        self::assertNull($point->phone);
        self::assertArrayHasKey('opening_hours', $point->raw());
    }

    public function testTheAddressIsTheCarriersOwnAndEmptyStringsAreNull(): void
    {
        $address = self::list('phrase')->points[0]->address;

        self::assertNotNull($address);
        self::assertSame('00026', $address->postcode, 'A postcode without the hyphen, as the carrier keeps it.');
        self::assertSame('RONDO ROMANA DMOWSKIEGOPAWILON NR 2 8', $address->street);
        self::assertSame('WARSZAWA', $address->city);
        self::assertSame('PL', $address->countryCode);
        self::assertNull($address->province, 'An empty string in the answer.');
        self::assertNull($address->houseNumber);
    }

    public function testTheOpeningHoursHaveAClosedSunday(): void
    {
        $hours = self::list('phrase')->points[0]->openingHours;

        self::assertInstanceOf(OpeningHours::class, $hours);
        self::assertSame('10:00', $hours->monday?->start);
        self::assertSame('20:00', $hours->friday?->end);
        self::assertNull($hours->sunday, 'Furgonetka sends null for a closed day.');
        self::assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], array_keys($hours->days()));
        self::assertNull($hours->days()['sunday']);
        self::assertSame('10:00', $hours->days()['saturday']?->start);
    }

    public function testTheCentreOfASearchByPhraseComesAsTextAndIsReadAsNumbers(): void
    {
        $centre = self::list('phrase')->coordinates;

        self::assertNotNull($centre);
        self::assertSame(52.23016, $centre->latitude);
        self::assertSame(21.0106, $centre->longitude);
        self::assertEqualsWithDelta(52.229872, self::list('fallback')->coordinates?->latitude, 0.000001, 'A search by coordinates or a fallback sends numbers.');
    }

    public function testAParcelLockerFoundById(): void
    {
        $list = self::list('by-id');
        $point = $list->first();

        self::assertNotNull($point);
        self::assertSame('WAW099', $list->searchedPointId);
        self::assertSame('WAW099', $point->code);
        self::assertSame(PointService::Inpost, $point->service);
        self::assertSame(PointServiceType::ParcelMachine, $point->serviceType);
        self::assertSame('PACZKOMAT', $point->type);
        self::assertTrue($point->cod);
        self::assertEqualsWithDelta(1.699, $point->distance, 0.001);
        self::assertSame('00-839', $point->address?->postcode);
        self::assertSame($point, $list->find('WAW099'));
        self::assertNull($list->find('WAW100'));
    }

    public function testASearchByCoordinatesFindsPointsOfSeveralCarriers(): void
    {
        $list = self::list('coordinates');

        self::assertSame(['orlen', 'poczta'], array_map(static fn (Point $p): ?string => $p->serviceValue, $list->points));
        self::assertSame('WS-360137-U6-03', $list->points[0]->identifier());
        self::assertSame(20.0, $list->points[1]->maxSupportedWeight);
        self::assertCount(1, $list->forService(PointService::Poczta));
        self::assertCount(1, $list->forService('orlen'));
        self::assertSame([], $list->forService(PointService::Ups));
        self::assertSame(52.2297, $list->coordinates?->latitude);
    }

    public function testASearchByAddress(): void
    {
        $list = self::list('address');

        self::assertSame(['FR_T-PM-03', 'KRA80M'], array_map(static fn (Point $p): ?string => $p->identifier(), $list->points));
        self::assertSame('APM', $list->points[0]->type);
        self::assertEqualsWithDelta(50.06271, $list->coordinates?->latitude, 0.00001);
    }

    public function testAPhraseTheSandboxCannotPlaceFallsBackToItsDefaultCentre(): void
    {
        $list = self::list('fallback');

        self::assertCount(2, $list, 'Not an error and not an empty list.');
        self::assertSame(['PL24557', 'PL63539'], array_map(static fn (Point $p): ?string => $p->code, $list->points));
        self::assertEqualsWithDelta(0.028, $list->points[0]->distance, 0.001);
    }

    public function testTheListIsIterableAndKeepsTheWholeAnswer(): void
    {
        $list = self::list('phrase');

        self::assertSame(['PL24557', 'PL69702'], array_map(static fn (Point $p): ?string => $p->code, iterator_to_array($list)));
        self::assertSame([], $list->recentlySelectedPoints);
        self::assertArrayHasKey('searchedPointId', $list->raw());
    }

    public function testAnIdentifierFromPointIdWinsOverTheCode(): void
    {
        $point = Point::fromArray(['point_id' => 'X1', 'code' => 'Y2']);

        self::assertSame('X1', $point->pointId);
        self::assertSame('X1', $point->identifier());
        self::assertNull(Point::fromArray([])->identifier());
    }

    public function testAServiceOrTypeThisReleaseDoesNotKnowKeepsTheRawValue(): void
    {
        $point = Point::fromArray(['code' => 'A', 'service' => 'furgonetkaPunktX', 'service_type' => 'drone_pad']);

        self::assertNull($point->service);
        self::assertSame('furgonetkaPunktX', $point->serviceValue);
        self::assertNull($point->serviceType);
        self::assertSame('drone_pad', $point->serviceTypeValue);
    }

    public function testTheHolidayAndTheBoxesAreRead(): void
    {
        $point = Point::fromArray([
            'code' => 'A',
            'holiday' => true,
            'holiday_period' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
            'boxes_specification' => [['gauge' => 'A', 'width' => 8, 'height' => 38, 'depth' => 64], ['gauge' => 'B', 'width' => 19, 'height' => 38, 'depth' => 64]],
            'photos' => ['https://example.com/a.jpg'],
            'opening_hours' => null,
        ]);

        self::assertTrue($point->holiday);
        self::assertSame('2026-10-01', $point->holidayStart?->format('Y-m-d'));
        self::assertSame('2026-10-07', $point->holidayEnd?->format('Y-m-d'));
        self::assertCount(2, $point->boxes ?? []);
        self::assertSame('B', $point->boxes[1]->gauge ?? null);
        self::assertSame(19, $point->boxes[1]->width ?? null);
        self::assertSame(['https://example.com/a.jpg'], $point->photos);
        self::assertNull($point->openingHours, 'A locker that is open all the time has no hours.');
    }

    public function testAnEmptyAnswerHasNoPoints(): void
    {
        $list = PointList::fromArray(['points' => [], 'recentlySelectedPoints' => []]);

        self::assertCount(0, $list);
        self::assertNull($list->first());
        self::assertNull($list->coordinates);
    }

    public function testCoordinatesAreReadFromTextAndWrittenWithoutNulls(): void
    {
        $centre = Coordinates::fromArray(['latitude' => '52.2301600000000', 'longitude' => 21]);

        self::assertSame(52.23016, $centre->latitude);
        self::assertSame(21.0, $centre->longitude);
        self::assertSame(['latitude' => 52.23016, 'longitude' => 21.0], $centre->toArray());
        self::assertSame(['latitude' => 1.5], (new Coordinates(1.5))->toArray());
        self::assertNull(Coordinates::fromArray(['latitude' => 'north'])->latitude);
    }

    public function testAnAddressIsWrittenWithoutNulls(): void
    {
        self::assertSame(
            ['postcode' => '31-021', 'city' => 'Kraków', 'country_code' => 'PL'],
            (new PointAddress(postcode: '31-021', city: 'Kraków', countryCode: 'PL'))->toArray(),
        );
    }

    public function testABoxOnTheMapIsFourCorners(): void
    {
        self::assertSame(
            [
                'north_west' => ['latitude' => 52.24, 'longitude' => 21.0],
                'north_east' => ['latitude' => 52.24, 'longitude' => 21.03],
                'south_west' => ['latitude' => 52.22, 'longitude' => 21.0],
                'south_east' => ['latitude' => 52.22, 'longitude' => 21.03],
            ],
            MapBounds::box(52.24, 21.0, 52.22, 21.03)->toArray(),
        );
    }
}
