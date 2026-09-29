<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

/**
 * The visible part of a map, to search for points inside it (`filters.map_bounds`).
 *
 * Four corners, as the API takes them. {@see self::box()} builds them from the four edges.
 */
final class MapBounds
{
    public function __construct(
        public readonly Coordinates $northWest,
        public readonly Coordinates $northEast,
        public readonly Coordinates $southWest,
        public readonly Coordinates $southEast,
    ) {
    }

    /**
     * A rectangle from its edges: latitudes of the north and south edges, longitudes of the west and east edges.
     */
    public static function box(float $north, float $west, float $south, float $east): self
    {
        return new self(
            new Coordinates($north, $west),
            new Coordinates($north, $east),
            new Coordinates($south, $west),
            new Coordinates($south, $east),
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return [
            'north_west' => $this->northWest->toArray(),
            'north_east' => $this->northEast->toArray(),
            'south_west' => $this->southWest->toArray(),
            'south_east' => $this->southEast->toArray(),
        ];
    }
}
