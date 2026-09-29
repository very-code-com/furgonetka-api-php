<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\PriceScenario;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * What a return or a readdressing of an ordered shipment would cost
 * (`POST /packages/return/calculate-price`, `POST /packages/readdressing/calculate-price`).
 *
 * Furgonetka gives both the same answer: a price and, sometimes, the scenario it came from. The
 * specification does not say whether the price is net or gross. Neither call has been answered
 * with a price on the sandbox (every shipment it was tried on was "not possible", see
 * `tests/Fixtures/errors/`), so this shape is the specification's, not a captured body.
 */
final class PriceEstimate
{
    /**
     * @param float|null           $price         The price, PLN; null when there is none (see {@see self::requiresIndividualPricing()}).
     * @param PriceScenario|null   $scenario      How it was priced; null when there is no scenario or it is not a member.
     * @param string|null          $scenarioValue Scenario as sent.
     * @param array<string, mixed> $raw           The whole answer.
     */
    public function __construct(
        public readonly ?float $price = null,
        public readonly ?PriceScenario $scenario = null,
        public readonly ?string $scenarioValue = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::float($data, 'price'),
            Value::enum($data, 'scenario', PriceScenario::class),
            Value::string($data, 'scenario'),
            $data,
        );
    }

    /** True when Furgonetka says the price is agreed one by one, so `price` is not the final figure. */
    public function requiresIndividualPricing(): bool
    {
        return $this->scenario === PriceScenario::IndividualPricingDhlFreight;
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
