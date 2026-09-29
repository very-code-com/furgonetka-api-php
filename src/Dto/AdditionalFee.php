<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** A fee charged on top of the price of a quote, for instance for a pickup (`additional_fees[]`). */
final class AdditionalFee
{
    /**
     * @param string|null  $type         Kind of fee.
     * @param float|null   $amount       Gross amount, PLN.
     * @param float|null   $amountNet    Net amount, PLN.
     * @param string|null  $description  Description.
     * @param list<string> $chargedItems What the fee is for.
     */
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?float $amount = null,
        public readonly ?float $amountNet = null,
        public readonly ?string $description = null,
        public readonly array $chargedItems = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'type'),
            Value::float($data, 'amount'),
            Value::float($data, 'amount_net'),
            Value::string($data, 'description'),
            Value::stringList($data, 'charged_items'),
        );
    }
}
