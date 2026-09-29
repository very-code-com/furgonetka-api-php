<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Dto\MapBounds;
use VeryCodeCom\FurgonetkaApi\Dto\PointAddress;
use VeryCodeCom\FurgonetkaApi\Enum\PointFilterType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;

/**
 * The request body of a points search. The shapes below are the ones the sandbox answered 200 to.
 */
final class PointsQueryTest extends TestCase
{
    public function testASearchByPhrase(): void
    {
        $query = new PointsQuery([PointService::Inpost, 'dpd'], searchPhrase: '00-026 Warszawa', limit: 3);

        self::assertSame(
            ['location' => ['search_phrase' => '00-026 Warszawa'], 'filters' => ['services' => ['inpost', 'dpd'], 'limit' => 3]],
            $query->toArray(),
        );
    }

    public function testASearchByCoordinatesWithARadius(): void
    {
        $query = new PointsQuery([PointService::Orlen, PointService::Poczta], coordinates: new Coordinates(52.2297, 21.0122), maxDistanceKm: 2.0, type: PointServiceType::ServicePoint, limit: 2);

        self::assertSame(
            [
                'location' => ['coordinates' => ['latitude' => 52.2297, 'longitude' => 21.0122], 'points_max_distance' => 2.0],
                'filters' => ['services' => ['orlen', 'poczta'], 'type' => 'service_point', 'limit' => 2],
            ],
            $query->toArray(),
        );
    }

    public function testASearchByAddress(): void
    {
        $query = new PointsQuery(
            [PointService::Inpost],
            address: new PointAddress(postcode: '31-021', street: 'Floriańska 15', city: 'Kraków', countryCode: 'PL'),
            pointTypes: [PointFilterType::AllPoints, 'cod_only'],
            countryCodes: ['PL'],
        );

        self::assertSame(
            [
                'location' => ['address' => ['postcode' => '31-021', 'street' => 'Floriańska 15', 'city' => 'Kraków', 'country_code' => 'PL']],
                'filters' => ['services' => ['inpost'], 'point_types' => ['all_points', 'cod_only'], 'country_codes' => ['PL']],
            ],
            $query->toArray(),
        );
    }

    public function testASearchInsideAPartOfTheMap(): void
    {
        $query = new PointsQuery([PointService::Inpost], coordinates: new Coordinates(52.2297, 21.0122), mapBounds: MapBounds::box(52.24, 21.0, 52.22, 21.03));

        $filters = $query->toArray()['filters'];

        self::assertSame(['services', 'map_bounds'], array_keys($filters));
        self::assertSame(['latitude' => 52.22, 'longitude' => 21.03], $filters['map_bounds']['south_east']);
    }

    public function testOnePointIsLookedUpByItsIdentifierAsThePhraseAndTheFilter(): void
    {
        $query = PointsQuery::forPoint('WAW099', [PointService::Inpost]);

        self::assertSame(
            ['location' => ['search_phrase' => 'WAW099'], 'filters' => ['services' => ['inpost'], 'point_id' => 'WAW099']],
            $query->toArray(),
        );
    }

    /** @return iterable<string, array{callable(): PointsQuery, string}> */
    public static function refused(): iterable
    {
        yield 'no carrier' => [static fn (): PointsQuery => new PointsQuery([], searchPhrase: 'Warszawa'), 'at least one carrier'];
        yield 'no place' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd]), 'coordinates, a searchPhrase or an address'];
        yield 'a blank phrase is no place' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd], searchPhrase: "  \n"), 'coordinates, a searchPhrase or an address'];
        yield 'half a coordinate' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd], coordinates: new Coordinates(52.2)), 'both latitude and longitude'];
        yield 'no radius' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd], searchPhrase: 'x', maxDistanceKm: 0.0), 'maxDistanceKm must be positive'];
        yield 'limit 0' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd], searchPhrase: 'x', limit: 0), 'between 1 and 2000'];
        yield 'limit 2001' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd], searchPhrase: 'x', limit: 2001), 'between 1 and 2000'];
        yield 'a carrier that is not an identifier' => [static fn (): PointsQuery => new PointsQuery(['in post'], searchPhrase: 'x'), 'not "in post"'];
        yield 'a carrier with a trailing newline' => [static fn (): PointsQuery => new PointsQuery(["dpd\n"], searchPhrase: 'x'), 'identifier'];
        yield 'a point type that is not an identifier' => [static fn (): PointsQuery => new PointsQuery([PointService::Dpd], searchPhrase: 'x', pointTypes: ['cod only']), 'identifier'];
    }

    /** @param callable(): PointsQuery $build */
    #[DataProvider('refused')]
    public function testARuleFromTheDocumentationIsEnforcedLocally(callable $build, string $message): void
    {
        try {
            $build();
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testTheLimitsThemselvesAreAllowed(): void
    {
        self::assertSame(1, (new PointsQuery([PointService::Dpd], searchPhrase: 'x', limit: 1))->limit);
        self::assertSame(2000, (new PointsQuery([PointService::Dpd], searchPhrase: 'x', limit: PointsQuery::MAX_LIMIT))->limit);
    }

    public function testSeveralWaysOfNamingThePlaceAreAllowedTogether(): void
    {
        $query = new PointsQuery([PointService::Dpd], coordinates: new Coordinates(1.0, 2.0), searchPhrase: 'Warszawa');

        self::assertSame(['coordinates', 'search_phrase'], array_keys($query->toArray()['location']));
    }

    public function testEveryFilterTypeIsAnIdentifier(): void
    {
        foreach (PointFilterType::cases() as $type) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/D', $type->value);
        }

        self::assertCount(13, PointFilterType::cases());
    }
}
