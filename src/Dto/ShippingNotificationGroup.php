<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The notification channels for one tracking state, for ordered shipments and for tracked ones.
 *
 * The group's `key` is the tracking state (`collected`, `delivery-problem`, ...), so `state` is that
 * state as an enum; `name` is a text for a person in the language of the request.
 */
final class ShippingNotificationGroup
{
    /**
     * @param string|null          $key      The state, as Furgonetka writes it.
     * @param TrackingState|null   $state    The state; null when it is not a member.
     * @param string|null          $name     Name for a person.
     * @param OrderChannels        $order    Channels for shipments you ordered.
     * @param TrackingChannels     $tracking Channels for shipments you only track.
     */
    public function __construct(
        public readonly ?string $key = null,
        public readonly ?TrackingState $state = null,
        public readonly ?string $name = null,
        public readonly OrderChannels $order = new OrderChannels(),
        public readonly TrackingChannels $tracking = new TrackingChannels(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'key'),
            Value::enum($data, 'key', TrackingState::class),
            Value::string($data, 'name'),
            OrderChannels::fromArray(Value::object($data, 'orderSettings') ?? []),
            TrackingChannels::fromArray(Value::object($data, 'trackingSettings') ?? []),
        );
    }
}
