<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;

/**
 * Which channels announce a change to one state for shipments you only track, as written back with
 * `saveNotificationSettings()`. A `null` channel is sent as `null`; give `false` to switch one off.
 */
final class TrackingNotificationRule
{
    /**
     * @param TrackingState|string $state   The state: `collected`, `delivery-problem` and so on.
     * @param bool|null            $webhook Post it to the webhook.
     * @param bool|null            $push    Send it to the mobile app.
     *
     * @throws \InvalidArgumentException when the state is not an identifier such as `collected`.
     */
    public function __construct(
        public readonly TrackingState|string $state,
        public readonly ?bool $webhook = null,
        public readonly ?bool $push = null,
    ) {
        OrderNotificationRule::checkState($state);
    }

    /** The state as Furgonetka writes it. */
    public function stateName(): string
    {
        return $this->state instanceof TrackingState ? $this->state->value : $this->state;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['state' => $this->stateName(), 'webhook' => $this->webhook, 'push' => $this->push];
    }
}
