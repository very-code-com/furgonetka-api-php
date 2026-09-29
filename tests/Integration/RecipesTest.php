<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use VeryCodeCom\FurgonetkaApi\Examples\Cookbook;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

require_once __DIR__ . '/../../examples/recipes/Scenario.php';
require_once __DIR__ . '/../../examples/recipes/Recipe.php';
require_once __DIR__ . '/../../examples/recipes/Outcome.php';
require_once __DIR__ . '/../../examples/recipes/Cookbook.php';

/**
 * The cookbook of `examples/recipes/` against the real sandbox: every recipe is validated and priced (or, for a
 * query, run) and has to come out the way its file says. A shipment recipe is never created or ordered.
 *
 * A recipe that shows a refusal names the error codes Furgonetka answers with. When one goes red the API has changed
 * a rule (or the account has), and the recipe, its text and docs/FURGONETKA-NOTES.md change with it.
 */
final class RecipesTest extends SandboxTestCase
{
    private static ?FurgonetkaApiClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recipes(): iterable
    {
        foreach (array_keys(Cookbook::all()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('recipes')]
    public function testARecipeComesOutAsItsFileSays(string $name): void
    {
        $recipe = Cookbook::all()[$name];
        $client = self::$client ?? throw new \LogicException('The client is created in setUp().');

        try {
            $outcome = Cookbook::check($recipe, $client);
        } catch (\RuntimeException $e) {
            self::markTestSkipped($name . ': ' . $e->getMessage());
        }

        if ($recipe->isShipment()) {
            self::assertTrue($outcome->isAsExpected($recipe), $name . ': Furgonetka answered ' . ($outcome->remote === [] ? 'with no errors' : implode(', ', $outcome->remote)) . ', the recipe says ' . ($recipe->refusedWith === [] ? 'it is accepted' : 'it is refused with ' . implode(', ', $recipe->refusedWith)));
        } else {
            self::assertNotSame([], $outcome->lines, $name . ' printed nothing.');
        }

        if ($recipe->isShipment() && $recipe->refusedWith === []) {
            self::assertTrue($outcome->priced, $name . ' is accepted but has no price: ' . implode('; ', $outcome->priceErrors));
        }
    }
}
