<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;

/**
 * Which channels announce a change to one state for shipments you ordered, as written back with
 * `saveNotificationSettings()`. A `null` channel is sent as `null`, the way Furgonetka reports one that
 * is not available; give `false` to switch a channel off.
 */
final class OrderNotificationRule
{
    /**
     * @param TrackingState|string $state   The state: `collected`, `delivery-problem` and so on.
     * @param bool|null            $webhook Post it to the webhook.
     * @param bool|null            $push    Send it to the mobile app.
     * @param bool|null            $sms     Send an SMS.
     * @param bool|null            $email   Send an e-mail.
     *
     * @throws \InvalidArgumentException when the state is not an identifier such as `collected`.
     */
    public function __construct(
        public readonly TrackingState|string $state,
        public readonly ?bool $webhook = null,
        public readonly ?bool $push = null,
        public readonly ?bool $sms = null,
        public readonly ?bool $email = null,
    ) {
        self::checkState($state);
    }

    /** The state as Furgonetka writes it. */
    public function stateName(): string
    {
        return $this->state instanceof TrackingState ? $this->state->value : $this->state;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['state' => $this->stateName(), 'webhook' => $this->webhook, 'push' => $this->push, 'sms' => $this->sms, 'email' => $this->email];
    }

    /** @internal */
    public static function checkState(TrackingState|string $state): void
    {
        $name = $state instanceof TrackingState ? $state->value : $state;

        if (preg_match('/^[a-z][a-z-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('A notification state is an identifier such as "collected" or "delivery-problem", not "' . $name . '".');
        }
    }
}
