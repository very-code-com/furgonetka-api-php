<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The general notification options of the account (`GET /account/settings/notification/general`):
 * e-mails with shipping documents, reports and the confirmation of an order.
 *
 * Together with {@see ShippingNotifications} and the webhook settings this is what
 * `saveNotificationSettings()` overwrites, see {@see NotificationSettingsRequest::from()}.
 *
 * @implements \IteratorAggregate<int, GeneralNotification>
 */
final class GeneralNotifications implements \Countable, \IteratorAggregate
{
    /**
     * @param list<GeneralNotification> $settings The options, in the order Furgonetka sent them.
     * @param array<string, mixed>      $raw      The whole answer.
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
        return new self(array_map(GeneralNotification::fromArray(...), Value::objectList($data, 'settings')), $data);
    }

    /** Number of options. */
    public function count(): int
    {
        return count($this->settings);
    }

    /** @return \ArrayIterator<int, GeneralNotification> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->settings);
    }

    /** The option with this key, or null. */
    public function find(string $key): ?GeneralNotification
    {
        foreach ($this->settings as $setting) {
            if ($setting->key === $key) {
                return $setting;
            }
        }

        return null;
    }

    /** Whether the option is on: true, false, or null when it is not available or unknown. */
    public function isEnabled(string $key): ?bool
    {
        return $this->find($key)?->enabled;
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
