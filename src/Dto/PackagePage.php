<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One page of a shipment list (`GET /packages`).
 *
 * The list has no page numbers: it runs from the newest shipment to the oldest, and the next page
 * starts after the last id of this one, see {@see self::nextLastPackageId()}. `count` is the size
 * of the whole result and Furgonetka sends it only for the `waiting` list; every other list has null.
 *
 * @implements \IteratorAggregate<int, Package>
 */
final class PackagePage implements \Countable, \IteratorAggregate
{
    /**
     * @param list<Package>        $packages The shipments of this page.
     * @param int|null             $count    Number of shipments in the whole result, `waiting` list only.
     * @param array<string, mixed> $raw      The whole answer.
     */
    public function __construct(
        public readonly array $packages = [],
        public readonly ?int $count = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(Package::fromArray(...), Value::objectList($data, 'packages')),
            Value::int($data, 'count'),
            $data,
        );
    }

    /** Number of shipments on this page. */
    public function count(): int
    {
        return count($this->packages);
    }

    /** @return \ArrayIterator<int, Package> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->packages);
    }

    /**
     * The `last_package_id` to ask for the next page: the id of the last shipment here, or null when
     * this page is empty (there is nothing after it).
     */
    public function nextLastPackageId(): ?string
    {
        $last = $this->packages === [] ? null : $this->packages[array_key_last($this->packages)];

        return $last?->id;
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
