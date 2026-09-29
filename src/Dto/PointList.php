<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The answer of a points search (`POST /points/map`): the points, nearest first, and the centre the
 * search used.
 *
 * A search that finds nothing near the phrase is not an error and not an empty list: the sandbox
 * answered a phrase it could not place with the points around its default centre in Warsaw. Check
 * `coordinates` and each point's `distance` before trusting that a point is where you asked.
 *
 * @implements \IteratorAggregate<int, Point>
 */
final class PointList implements \Countable, \IteratorAggregate
{
    /**
     * @param list<Point>          $points                 The points, nearest to the centre first.
     * @param Coordinates|null     $coordinates            The centre of the search.
     * @param string|null          $searchedPointId        The id that was searched for, when the search was by id.
     * @param list<Point>          $recentlySelectedPoints Points the account used recently.
     * @param array<string, mixed> $raw                    The whole answer.
     */
    public function __construct(
        public readonly array $points = [],
        public readonly ?Coordinates $coordinates = null,
        public readonly ?string $searchedPointId = null,
        public readonly array $recentlySelectedPoints = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $coordinates = Value::object($data, 'coordinates');

        return new self(
            array_map(Point::fromArray(...), Value::objectList($data, 'points')),
            $coordinates !== null ? Coordinates::fromArray($coordinates) : null,
            Value::string($data, 'searchedPointId'),
            array_map(Point::fromArray(...), Value::objectList($data, 'recentlySelectedPoints')),
            $data,
        );
    }

    /** Number of points. */
    public function count(): int
    {
        return count($this->points);
    }

    /** @return \ArrayIterator<int, Point> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->points);
    }

    /** The nearest point, or null when there is none. */
    public function first(): ?Point
    {
        return $this->points[0] ?? null;
    }

    /** The point with this identifier, or null. */
    public function find(string $identifier): ?Point
    {
        foreach ($this->points as $point) {
            if ($point->identifier() === $identifier) {
                return $point;
            }
        }

        return null;
    }

    /**
     * The points of one carrier, in the order they came.
     *
     * @return list<Point>
     */
    public function forService(PointService|string $service): array
    {
        $name = $service instanceof PointService ? $service->value : $service;

        return array_values(array_filter($this->points, static fn (Point $point): bool => $point->serviceValue === $name));
    }

    /**
     * The whole answer as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
