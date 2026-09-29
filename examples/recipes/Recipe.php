<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Examples;

use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

/**
 * One scenario of the cookbook: what it is, and how to build it.
 *
 * A recipe file returns one. There are two kinds:
 *
 *   - a shipment recipe builds a ShipmentRequest for one carrier. It is checked (validated and priced) and
 *     never created or ordered;
 *   - a query recipe makes read-only calls and returns the lines it wants printed.
 *
 * `php examples/recipes/run.php <name>` runs one, `all` runs every recipe, and `tests/Integration/RecipesTest.php`
 * checks them against the sandbox, so a recipe cannot go stale unnoticed.
 */
final class Recipe
{
    /**
     * @param \Closure(Scenario): ShipmentRequest|null $request
     * @param \Closure(FurgonetkaApiClient): list<string>|null $query
     * @param list<string> $refusedWith The error codes Furgonetka answers with when the recipe shows a refusal; empty when it must be accepted.
     */
    private function __construct(
        public readonly string $title,
        public readonly string $shows,
        public readonly ?Carrier $carrier,
        private readonly ?\Closure $request,
        private readonly ?\Closure $query,
        public readonly array $refusedWith,
    ) {
    }

    /**
     * A shipment recipe.
     *
     * @param string                          $title       A few words.
     * @param string                          $shows       What to learn from it, in a sentence or two.
     * @param Carrier                         $carrier     Whose contract the shipment is validated and priced on.
     * @param \Closure(Scenario): ShipmentRequest $request Builds the shipment; `$s->request(...)` gives the defaults.
     * @param list<string>                    $refusedWith Error codes that Furgonetka answers the shipment with, for a recipe that shows a refusal.
     */
    public static function shipment(string $title, string $shows, Carrier $carrier, \Closure $request, array $refusedWith = []): self
    {
        return new self($title, $shows, $carrier, $request, null, $refusedWith);
    }

    /**
     * A query recipe: read-only calls whose result is a few lines of text.
     *
     * @param \Closure(FurgonetkaApiClient): list<string> $query
     */
    public static function query(string $title, string $shows, \Closure $query): self
    {
        return new self($title, $shows, null, null, $query, []);
    }

    public function isShipment(): bool
    {
        return $this->request !== null;
    }

    /**
     * @throws \LogicException when this is a query recipe.
     * @throws \RuntimeException when the account has no contract with the carrier.
     */
    public function shipmentRequest(FurgonetkaApiClient $client): ShipmentRequest
    {
        if ($this->request === null || $this->carrier === null) {
            throw new \LogicException('This recipe is a query, not a shipment.');
        }

        return ($this->request)(new Scenario($client, $this->carrier));
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException when this is a shipment recipe.
     */
    public function lines(FurgonetkaApiClient $client): array
    {
        if ($this->query === null) {
            throw new \LogicException('This recipe is a shipment, not a query.');
        }

        return ($this->query)($client);
    }
}
