<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The tracking of a shipment (`GET /packages/{id}/tracking`, version 2): one entry per parcel,
 * keyed by the carrier's waybill number.
 *
 * A shipment added with `addToTracking()` has one entry under the number you gave. A shipment that
 * was ordered but has not moved answers with an entry per parcel and an empty history.
 *
 * @implements \IteratorAggregate<string, ParcelTracking>
 */
final class TrackingResult implements \Countable, \IteratorAggregate
{
    /**
     * @param array<int|string, ParcelTracking> $parcels Tracking per waybill number. PHP turns an all-digit number into an integer key; {@see self::numbers()} gives them back as text.
     * @param array<string, mixed>              $raw     The whole answer.
     */
    public function __construct(
        public readonly array $parcels = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(ParcelTracking::fromArray(...), Value::objectMap($data, 'parcels')), $data);
    }

    /** Number of parcels. */
    public function count(): int
    {
        return count($this->parcels);
    }

    /** @return \Generator<string, ParcelTracking> */
    public function getIterator(): \Generator
    {
        foreach ($this->parcels as $number => $parcel) {
            yield (string) $number => $parcel;
        }
    }

    /**
     * The waybill numbers, in the order Furgonetka sent them.
     *
     * @return list<string>
     */
    public function numbers(): array
    {
        return array_map(strval(...), array_keys($this->parcels));
    }

    /** The tracking of the parcel with this waybill number, or null when the shipment has no such parcel. */
    public function parcel(string $number): ?ParcelTracking
    {
        return $this->parcels[$number] ?? null;
    }

    /** The first parcel: the one of a single-parcel shipment. */
    public function first(): ?ParcelTracking
    {
        foreach ($this->parcels as $parcel) {
            return $parcel;
        }

        return null;
    }

    /** True when every parcel has been delivered; false for a shipment with no parcel entry. */
    public function isDelivered(): bool
    {
        foreach ($this->parcels as $parcel) {
            if (!$parcel->isDelivered()) {
                return false;
            }
        }

        return $this->parcels !== [];
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
