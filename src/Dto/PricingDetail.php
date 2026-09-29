<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** The price of one additional service or surcharge (`pricing.details[]`), for instance `fuel_surcharge`. */
final class PricingDetail
{
    /**
     * @param string|null $service     Symbol of the service or surcharge (`cod`, `insurance`, `fuel_surcharge`, `road_tolls`, ...).
     * @param float|null  $priceNet    Net price, PLN.
     * @param string|null $description Description in the language of the request.
     */
    public function __construct(
        public readonly ?string $service = null,
        public readonly ?float $priceNet = null,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::string($data, 'service'), Value::float($data, 'price_net'), Value::string($data, 'description'));
    }
}
