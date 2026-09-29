<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * What a shipment costs (`pricing`, version 2), for a price quote or a created shipment.
 *
 * Amounts are PLN. `details` prices every additional service and surcharge separately, which is
 * what version 2 added. The price of a quote that is not available (see {@see PriceQuote}) can be
 * null.
 */
final class Pricing
{
    /**
     * @param float|null          $priceGross                    Gross price.
     * @param float|null          $priceNet                      Net price.
     * @param float|null          $priceBaseNet                  Net price without additional services.
     * @param float|null          $priceOrg                      Original gross price, before any correction.
     * @param float|null          $adjustedPrice                 Price after a correction.
     * @param int|null            $tax                           VAT, in percent.
     * @param string|null         $priceInfo                     Extra information, for instance "+ podjazd".
     * @param float|null          $additionalServicesPriceNet    Sum of the net prices of the additional services (without insurance and the fuel and road surcharges).
     * @param float|null          $additionalServicesPriceGross  The same, gross.
     * @param list<PricingDetail> $details                       The price of each additional service and surcharge.
     */
    public function __construct(
        public readonly ?float $priceGross = null,
        public readonly ?float $priceNet = null,
        public readonly ?float $priceBaseNet = null,
        public readonly ?float $priceOrg = null,
        public readonly ?float $adjustedPrice = null,
        public readonly ?int $tax = null,
        public readonly ?string $priceInfo = null,
        public readonly ?float $additionalServicesPriceNet = null,
        public readonly ?float $additionalServicesPriceGross = null,
        public readonly array $details = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            priceGross: Value::float($data, 'price_gross'),
            priceNet: Value::float($data, 'price_net'),
            priceBaseNet: Value::float($data, 'price_base_net'),
            priceOrg: Value::float($data, 'price_org'),
            adjustedPrice: Value::float($data, 'adjusted_price'),
            tax: Value::int($data, 'tax'),
            priceInfo: Value::string($data, 'price_info'),
            additionalServicesPriceNet: Value::float($data, 'additional_services_price_net'),
            additionalServicesPriceGross: Value::float($data, 'additional_services_price_gross'),
            details: array_map(PricingDetail::fromArray(...), Value::objectList($data, 'details')),
        );
    }
}
