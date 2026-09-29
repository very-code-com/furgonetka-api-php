<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;

/**
 * The notification settings of the account as `saveNotificationSettings()` writes them.
 *
 * **A save replaces all of it**: every state of both lists, the e-mail options and the webhook. The
 * sandbox refused an empty list, and changed the general settings when `emailBit` was left out, so
 * this request always carries everything. To change one thing, read the three parts and start from
 * them:
 *
 *   $request = NotificationSettingsRequest::from(
 *       $client->fetchShippingNotifications(),
 *       $client->fetchGeneralNotifications(),
 *       $client->fetchWebhookSettings(),
 *   )->withOrder(new OrderNotificationRule(TrackingState::Delivered, webhook: true));
 *
 *   $client->saveNotificationSettings($request);
 *
 * The webhook token is a secret (the salt of every notification's checksum): it shows as `[redacted]`
 * in dumps and is masked in the request body of a debug report. `gieldaPushNotifications` (the
 * freight exchange's push notifications) cannot be read, so it is not carried over; set it or leave it
 * out.
 */
final class NotificationSettingsRequest
{
    /**
     * @param list<OrderNotificationRule>    $orderNotifications    One rule per state, for ordered shipments; not empty.
     * @param list<TrackingNotificationRule> $trackingNotifications One rule per state, for tracked shipments; not empty.
     * @param EmailNotifications             $emailBit              The e-mail options.
     * @param string|null                    $webhookUrl            Where to post notifications; null for none.
     * @param string|null                    $webhookToken          The webhook's checksum salt.
     * @param WebhookFormat|null             $webhookFormat         Format of the notifications; required with a URL.
     * @param bool|null                      $gieldaPushNotifications Push notifications of the freight exchange.
     *
     * @throws \InvalidArgumentException when a list is empty or a webhook URL has no format.
     */
    public function __construct(
        public readonly array $orderNotifications,
        public readonly array $trackingNotifications,
        public readonly EmailNotifications $emailBit,
        public readonly ?string $webhookUrl = null,
        #[\SensitiveParameter]
        public readonly ?string $webhookToken = null,
        public readonly ?WebhookFormat $webhookFormat = null,
        public readonly ?bool $gieldaPushNotifications = null,
    ) {
        if ($orderNotifications === [] || $trackingNotifications === []) {
            throw new \InvalidArgumentException('NotificationSettingsRequest: both lists need at least one state; Furgonetka refuses an empty one.');
        }

        if (trim($webhookUrl ?? '') !== '' && $webhookFormat === null) {
            throw new \InvalidArgumentException('NotificationSettingsRequest: a webhook URL needs its format.');
        }
    }

    /**
     * The request that holds what the account has now, from the three reads.
     *
     * @throws \InvalidArgumentException when the reads leave a list empty.
     */
    public static function from(ShippingNotifications $shipping, GeneralNotifications $general, WebhookSettings $webhook): self
    {
        $order = [];
        $tracking = [];

        foreach ($shipping->settings as $group) {
            if ($group->key === null) {
                continue;
            }

            $order[] = new OrderNotificationRule($group->key, $group->order->webhook, $group->order->push, $group->order->sms, $group->order->email);
            $tracking[] = new TrackingNotificationRule($group->key, $group->tracking->webhook, $group->tracking->push);
        }

        return new self($order, $tracking, EmailNotifications::fromGeneral($general), $webhook->url, $webhook->token, $webhook->format);
    }

    /** A copy with the rule for that state replaced, or added when the state was not there. */
    public function withOrder(OrderNotificationRule $rule): self
    {
        $rules = array_values(array_filter($this->orderNotifications, static fn (OrderNotificationRule $r): bool => $r->stateName() !== $rule->stateName()));
        $rules[] = $rule;

        return $this->copy(orderNotifications: $rules);
    }

    /** A copy with the rule for that state replaced, or added when the state was not there. */
    public function withTracking(TrackingNotificationRule $rule): self
    {
        $rules = array_values(array_filter($this->trackingNotifications, static fn (TrackingNotificationRule $r): bool => $r->stateName() !== $rule->stateName()));
        $rules[] = $rule;

        return $this->copy(trackingNotifications: $rules);
    }

    /** A copy with other e-mail options. */
    public function withEmail(EmailNotifications $emailBit): self
    {
        return $this->copy(emailBit: $emailBit);
    }

    /** A copy with another webhook; give nulls to switch it off. */
    public function withWebhook(?string $url, #[\SensitiveParameter] ?string $token, ?WebhookFormat $format): self
    {
        return $this->copy(webhookUrl: $url, webhookToken: $token, webhookFormat: $format, replaceWebhook: true);
    }

    /**
     * @param list<OrderNotificationRule>|null    $orderNotifications
     * @param list<TrackingNotificationRule>|null $trackingNotifications
     */
    private function copy(
        ?array $orderNotifications = null,
        ?array $trackingNotifications = null,
        ?EmailNotifications $emailBit = null,
        ?string $webhookUrl = null,
        #[\SensitiveParameter]
        ?string $webhookToken = null,
        ?WebhookFormat $webhookFormat = null,
        bool $replaceWebhook = false,
    ): self {
        return new self(
            $orderNotifications ?? $this->orderNotifications,
            $trackingNotifications ?? $this->trackingNotifications,
            $emailBit ?? $this->emailBit,
            $replaceWebhook ? $webhookUrl : $this->webhookUrl,
            $replaceWebhook ? $webhookToken : $this->webhookToken,
            $replaceWebhook ? $webhookFormat : $this->webhookFormat,
            $this->gieldaPushNotifications,
        );
    }

    /**
     * The body of the request. Every state, every option and the webhook are written, with `null`s
     * where the value is null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'orderNotifications' => array_map(static fn (OrderNotificationRule $r): array => $r->toArray(), $this->orderNotifications),
            'trackingNotifications' => array_map(static fn (TrackingNotificationRule $r): array => $r->toArray(), $this->trackingNotifications),
            'emailBit' => $this->emailBit->toArray(),
            'webhookUrl' => $this->webhookUrl,
            'webhookToken' => $this->webhookToken,
            'webhookFormat' => $this->webhookFormat?->value,
        ];

        if ($this->gieldaPushNotifications !== null) {
            $body['gieldaPushNotifications'] = $this->gieldaPushNotifications;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $fields = get_object_vars($this);
        $fields['webhookToken'] = $this->webhookToken === null ? null : '[redacted]';

        return $fields;
    }
}
