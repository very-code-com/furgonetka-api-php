<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

/**
 * The general e-mail options, as written back with `saveNotificationSettings()` (`emailBit`).
 *
 * **Leaving these out of a save changes them**: the sandbox's general settings were different after a
 * save without `emailBit`. So the request always sends all five, and {@see NotificationSettingsRequest::from()}
 * fills them from what {@see GeneralNotifications} read. A `null` is sent as `null`, the way Furgonetka
 * reports an option the account does not have.
 */
final class EmailNotifications
{
    /**
     * @param bool|null $shipmentDox                  The shipping documents by e-mail.
     * @param bool|null $packageReport                Monthly reports of delivered shipments.
     * @param bool|null $codReport                    Monthly reports of cash-on-delivery shipments.
     * @param bool|null $orderConfirmation            The confirmation of an order, to the receiver.
     * @param bool|null $resignTocPricelistAttachment Do not attach the terms and the price list.
     */
    public function __construct(
        public readonly ?bool $shipmentDox = null,
        public readonly ?bool $packageReport = null,
        public readonly ?bool $codReport = null,
        public readonly ?bool $orderConfirmation = null,
        public readonly ?bool $resignTocPricelistAttachment = null,
    ) {
    }

    /** The five options from what the general settings say. */
    public static function fromGeneral(GeneralNotifications $general): self
    {
        return new self(
            $general->isEnabled('shipmentDox'),
            $general->isEnabled('packageReport'),
            $general->isEnabled('codReport'),
            $general->isEnabled('orderConfirmation'),
            $general->isEnabled('resignTOCPricelistAttachment'),
        );
    }

    /** @return array<string, bool|null> */
    public function toArray(): array
    {
        return [
            'shipmentDox' => $this->shipmentDox,
            'packageReport' => $this->packageReport,
            'codReport' => $this->codReport,
            'orderConfirmation' => $this->orderConfirmation,
            'resignTOCPricelistAttachment' => $this->resignTocPricelistAttachment,
        ];
    }
}
