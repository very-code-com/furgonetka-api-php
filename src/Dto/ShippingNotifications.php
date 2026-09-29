<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The shipping notification settings of the account
 * (`GET /account/settings/notification/shipping`): for every tracking state, which channels announce
 * it.
 *
 * The sandbox listed eleven states, without `waiting` and `ordered`. Together with
 * {@see GeneralNotifications} and the webhook settings this is what `saveNotificationSettings()`
 * overwrites, see {@see NotificationSettingsRequest::from()}.
 *
 * @implements \IteratorAggregate<int, ShippingNotificationGroup>
 */
final class ShippingNotifications implements \Countable, \IteratorAggregate
{
    /**
     * @param list<ShippingNotificationGroup> $settings One group per state.
     * @param array<string, mixed>            $raw      The whole answer.
     */
    public function __construct(
        public readonly array $settings = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(ShippingNotificationGroup::fromArray(...), Value::objectList($data, 'settings')), $data);
    }

    /** Number of states. */
    public function count(): int
    {
        return count($this->settings);
    }

    /** @return \ArrayIterator<int, ShippingNotificationGroup> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->settings);
    }

    /** The group of a state, or null when Furgonetka does not list it. */
    public function forState(TrackingState|string $state): ?ShippingNotificationGroup
    {
        $key = $state instanceof TrackingState ? $state->value : $state;

        foreach ($this->settings as $group) {
            if ($group->key === $key) {
                return $group;
            }
        }

        return null;
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
