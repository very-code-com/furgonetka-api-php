<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Query;

use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Dto\MapBounds;
use VeryCodeCom\FurgonetkaApi\Dto\PointAddress;
use VeryCodeCom\FurgonetkaApi\Enum\PointFilterType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\PointServiceType;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * What to ask `POST /points/map` for: where to look and which carriers' points to look for.
 *
 * Give the place as `coordinates`, a `searchPhrase` (an address, part of one, a postcode or a point
 * id) or an `address`; the documentation says one of them is required and the three are
 * interchangeable. `services` is required. A search that Furgonetka cannot place does not fail, it
 * falls back to a default centre, see {@see \VeryCodeCom\FurgonetkaApi\Dto\PointList}.
 *
 *   $points = $client->findPoints(new PointsQuery(
 *       services: [PointService::Inpost],
 *       searchPhrase: '00-026 Warszawa',
 *       limit: 5,
 *   ));
 *
 * To look one point up by its identifier use {@see self::forPoint()}.
 */
final class PointsQuery
{
    /** The most points Furgonetka returns at once. */
    public const MAX_LIMIT = 2000;

    /**
     * @param list<PointService|string>     $services      Carriers whose points to search; at least one.
     * @param Coordinates|null              $coordinates   Centre of the search; both coordinates set.
     * @param string|null                   $searchPhrase  Address, part of an address, postcode or point id.
     * @param PointAddress|null             $address       Address to centre the search on.
     * @param float|null                    $maxDistanceKm Search radius in km; Furgonetka's default is 10.
     * @param list<PointFilterType|string>  $pointTypes    Kinds of point to narrow to or leave out.
     * @param PointServiceType|null         $type          Only lockers or only staffed points.
     * @param list<string>                  $countryCodes  Only points in these countries (ISO 3166-1 alpha-2).
     * @param string|null                   $pointId       Return only the point with this identifier.
     * @param int|null                      $limit         Number of points, 1 to 2000; Furgonetka's default is 100.
     * @param MapBounds|null                $mapBounds     Only points inside this part of the map.
     *
     * @throws \InvalidArgumentException when a rule stated in the documentation is broken.
     */
    public function __construct(
        public readonly array $services,
        public readonly ?Coordinates $coordinates = null,
        public readonly ?string $searchPhrase = null,
        public readonly ?PointAddress $address = null,
        public readonly ?float $maxDistanceKm = null,
        public readonly array $pointTypes = [],
        public readonly ?PointServiceType $type = null,
        public readonly array $countryCodes = [],
        public readonly ?string $pointId = null,
        public readonly ?int $limit = null,
        public readonly ?MapBounds $mapBounds = null,
    ) {
        if ($services === []) {
            throw new \InvalidArgumentException('PointsQuery: give at least one carrier in services.');
        }

        $phrase = $searchPhrase !== null && trim($searchPhrase) !== '';

        if ($coordinates === null && !$phrase && $address === null) {
            throw new \InvalidArgumentException('PointsQuery: give coordinates, a searchPhrase or an address.');
        }

        if ($coordinates !== null && ($coordinates->latitude === null || $coordinates->longitude === null)) {
            throw new \InvalidArgumentException('PointsQuery: coordinates need both latitude and longitude.');
        }

        if ($maxDistanceKm !== null && $maxDistanceKm <= 0) {
            throw new \InvalidArgumentException('PointsQuery: maxDistanceKm must be positive.');
        }

        if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new \InvalidArgumentException('PointsQuery: limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }

        foreach ([...$services, ...$pointTypes] as $item) {
            $name = $item instanceof \BackedEnum ? (string) $item->value : $item;

            if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
                throw new \InvalidArgumentException('PointsQuery: a carrier or a point type is an identifier such as "inpost" or "cod_only", not "' . $name . '".');
            }
        }
    }

    /**
     * Look up one point by its identifier: the identifier goes in as the search phrase and as the
     * point filter, the way the sandbox answered it.
     *
     * @param list<PointService|string> $services The carrier of the point, usually one.
     */
    public static function forPoint(string $pointId, array $services): self
    {
        return new self($services, searchPhrase: $pointId, pointId: $pointId);
    }

    /**
     * The body of the request.
     *
     * @return array{location: array<string, mixed>, filters: array<string, mixed>}
     */
    public function toArray(): array
    {
        $names = static fn (array $items): array => array_map(
            static fn (PointService|PointFilterType|string $item): string => $item instanceof \BackedEnum ? (string) $item->value : $item,
            $items,
        );

        return [
            'location' => Payload::compact([
                'coordinates' => $this->coordinates?->toArray(),
                'search_phrase' => $this->searchPhrase,
                'address' => $this->address?->toArray(),
                'points_max_distance' => $this->maxDistanceKm,
            ]),
            'filters' => Payload::compact([
                'services' => $names($this->services),
                'point_types' => $names($this->pointTypes),
                'map_bounds' => $this->mapBounds?->toArray(),
                'type' => $this->type?->value,
                'country_codes' => $this->countryCodes,
                'point_id' => $this->pointId,
                'limit' => $this->limit,
            ]),
        ];
    }
}
