<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The tracking of one parcel: its history and the carrier's own tracking links.
 *
 * Furgonetka lists the events newest first (seen on the sandbox), and a parcel that has no event
 * yet has an empty history. {@see self::latest()} does not rely on the order: it takes the event
 * with the latest time.
 *
 * `additionalInfoTracking` is an array in the specification and an object in the answers; both
 * are read.
 */
final class ParcelTracking
{
    /**
     * @param list<TrackingEvent>  $events              The history as sent, newest first.
     * @param string|null          $pickupTrackingUrl   The carrier's page for the pickup (GLS).
     * @param string|null          $deliveryTrackingUrl The carrier's page for the delivery (GLS).
     * @param array<string, mixed> $raw                 The whole object as Furgonetka sent it.
     */
    public function __construct(
        public readonly array $events = [],
        public readonly ?string $pickupTrackingUrl = null,
        public readonly ?string $deliveryTrackingUrl = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $info = Value::object($data, 'additionalInfoTracking');
        $pickup = null;
        $delivery = null;

        foreach ($info !== null ? [$info] : Value::objectList($data, 'additionalInfoTracking') as $item) {
            $pickup ??= Value::string($item, 'pickupTrackingUrl');
            $delivery ??= Value::string($item, 'deliveryTrackingUrl');
        }

        return new self(
            array_map(TrackingEvent::fromArray(...), Value::objectList($data, 'tracking')),
            $pickup,
            $delivery,
            $data,
        );
    }

    /** The most recent event, or null while the parcel has none. */
    public function latest(): ?TrackingEvent
    {
        $latest = null;

        foreach ($this->events as $event) {
            if ($latest === null || ($event->datetime !== null && ($latest->datetime === null || $event->datetime > $latest->datetime))) {
                $latest = $event;
            }
        }

        return $latest;
    }

    /** State of the most recent event; null while there is none or when it is not a known state. */
    public function state(): ?TrackingState
    {
        return $this->latest()?->state;
    }

    /** True when the most recent event says the parcel was delivered. */
    public function isDelivered(): bool
    {
        return $this->state() === TrackingState::Delivered;
    }

    /**
     * The whole object as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
