<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Examples;

/**
 * What running a recipe found.
 */
final class Outcome
{
    /**
     * @param array<string, mixed> $request     The body of the shipment as it goes on the wire (shipment recipes).
     * @param list<string>         $local       Errors of the local rules.
     * @param list<string>         $warnings    Warnings of the local rules.
     * @param list<string>         $remote      Errors Furgonetka answered with, as "code path".
     * @param list<string>         $remoteCodes The same errors, codes only.
     * @param list<string>         $priceErrors Why a price is not available.
     * @param list<string>         $lines       The output of a query recipe.
     */
    public function __construct(
        public readonly array $request = [],
        public readonly array $local = [],
        public readonly array $warnings = [],
        public readonly array $remote = [],
        public readonly array $remoteCodes = [],
        public readonly bool $priced = false,
        public readonly ?float $price = null,
        public readonly array $priceErrors = [],
        public readonly array $lines = [],
    ) {
    }

    /** True when the outcome is the one the recipe says it has: accepted, or refused with the codes it names. */
    public function isAsExpected(Recipe $recipe): bool
    {
        if (!$recipe->isShipment()) {
            return $this->lines !== [];
        }

        if ($recipe->refusedWith === []) {
            return $this->remote === [];
        }

        return array_diff($recipe->refusedWith, $this->remoteCodes) === [];
    }
}
