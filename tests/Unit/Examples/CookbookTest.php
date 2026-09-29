<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Examples;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Examples\Cookbook;
use VeryCodeCom\FurgonetkaApi\Examples\Outcome;
use VeryCodeCom\FurgonetkaApi\Examples\Recipe;

require_once __DIR__ . '/../../../examples/recipes/Scenario.php';
require_once __DIR__ . '/../../../examples/recipes/Recipe.php';
require_once __DIR__ . '/../../../examples/recipes/Outcome.php';
require_once __DIR__ . '/../../../examples/recipes/Cookbook.php';

/**
 * The cookbook of `examples/recipes/`, offline: that it is complete, that every recipe is written the same way and
 * listed in its README, and how an outcome is compared with what a recipe says. That the recipes behave as they say
 * is `Integration\RecipesTest`, which needs the sandbox.
 */
final class CookbookTest extends TestCase
{
    private static function readme(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../examples/recipes/README.md');
    }

    public function testThereAreMoreThanAHundredAndFiftyRecipesOfBothKinds(): void
    {
        $recipes = Cookbook::all();
        $shipments = array_filter($recipes, static fn (Recipe $r): bool => $r->isShipment());

        self::assertGreaterThan(150, count($recipes));
        self::assertGreaterThan(100, count($shipments));
        self::assertGreaterThan(50, count($recipes) - count($shipments));
    }

    public function testEveryRecipeIsListedInTheReadmeOfTheCookbook(): void
    {
        $readme = self::readme();

        foreach (array_keys(Cookbook::all()) as $name) {
            self::assertStringContainsString('`' . $name . '`', $readme, $name . ' is not in examples/recipes/README.md.');
        }
    }

    public function testEveryRecipeHasATitleAndATextThatSaysWhatItShows(): void
    {
        $titles = [];

        foreach (Cookbook::all() as $name => $recipe) {
            self::assertMatchesRegularExpression('#^[a-z0-9]+(/[a-z0-9]+(-[a-z0-9]+)*)+$#D', $name, 'The name is lower case words joined by hyphens.');
            self::assertGreaterThanOrEqual(12, strlen($recipe->title), $name);
            self::assertGreaterThanOrEqual(60, strlen($recipe->shows), $name . ' says too little about what it shows.');
            self::assertStringEndsWith('.', $recipe->shows, $name);
            self::assertArrayNotHasKey($recipe->title, $titles, $name . ' has the title of ' . ($titles[$recipe->title] ?? ''));
            $titles[$recipe->title] = $name;
        }
    }

    public function testThePublishedTextIsPlainAscii(): void
    {
        foreach (Cookbook::all() as $name => $recipe) {
            self::assertSame(1, preg_match('/^[\x20-\x7E]+$/D', $recipe->title . $recipe->shows), $name . ' has a character that is not plain ASCII.');
        }
    }

    public function testAShipmentRecipeNamesItsCarrierAndTheNameStartsWithIt(): void
    {
        foreach (Cookbook::all() as $name => $recipe) {
            if (!$recipe->isShipment()) {
                self::assertNull($recipe->carrier, $name);

                continue;
            }

            self::assertNotNull($recipe->carrier, $name);
            self::assertStringStartsWith('shipments/' . strtolower($recipe->carrier->value) . '/', $name);
        }
    }

    public function testARecipeThatShowsARefusalIsNamedAfterIt(): void
    {
        foreach (Cookbook::all() as $name => $recipe) {
            $refusal = str_contains($name, '-refused') || str_contains($name, '-not-available');

            self::assertSame($recipe->refusedWith !== [], $refusal, $name . ': a refusal recipe carries error codes, ends its name in -refused or -not-available, and only such a recipe does.');
        }
    }

    public function testAQueryRecipeCannotBeBuiltAsAShipmentAndTheOtherWayRound(): void
    {
        $recipes = Cookbook::all();
        $query = $recipes['points/orlen'];
        $shipment = $recipes['shipments/dpd/basic'];
        $client = \VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient::create(\VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig::sandbox());

        $refused = 0;

        foreach ([static fn () => $query->shipmentRequest($client), static fn () => $shipment->lines($client)] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (\LogicException) {
                ++$refused;
            }
        }

        self::assertSame(2, $refused);
    }

    public function testAnOutcomeIsComparedWithWhatTheRecipeSays(): void
    {
        $accepted = Recipe::shipment('A recipe that is accepted', 'It is accepted by Furgonetka and priced, which is all there is to say about it.', Carrier::Dpd, static fn () => throw new \LogicException('not called'));
        $refused = Recipe::shipment('A recipe that is refused', 'It is refused with two codes; more codes than these in the answer are fine, fewer are not.', Carrier::Dpd, static fn () => throw new \LogicException('not called'), ['notSupported', 'isEmpty']);

        self::assertTrue((new Outcome(remote: [], remoteCodes: []))->isAsExpected($accepted));
        self::assertFalse((new Outcome(remote: ['isEmpty /x'], remoteCodes: ['isEmpty']))->isAsExpected($accepted));

        self::assertTrue((new Outcome(remote: ['notSupported /a', 'isEmpty /b'], remoteCodes: ['notSupported', 'isEmpty']))->isAsExpected($refused));
        self::assertTrue((new Outcome(remote: ['x', 'y', 'z'], remoteCodes: ['isEmpty', 'other', 'notSupported']))->isAsExpected($refused), 'Extra errors do not matter.');
        self::assertFalse((new Outcome(remote: ['notSupported /a'], remoteCodes: ['notSupported']))->isAsExpected($refused), 'A missing code does.');
        self::assertFalse((new Outcome())->isAsExpected($refused), 'Accepted is not refused.');
    }

    public function testAQueryOutcomeIsAsExpectedWhenItPrintedSomething(): void
    {
        $query = Recipe::query('A query recipe with a title', 'It prints a few lines about something the account has, which is what it shows.', static fn (): array => ['a line']);

        self::assertTrue((new Outcome(lines: ['a line']))->isAsExpected($query));
        self::assertFalse((new Outcome(lines: []))->isAsExpected($query));
    }

    public function testAFileThatIsNotARecipeIsRefused(): void
    {
        $directory = __DIR__ . '/../../../examples/recipes';
        $stray = $directory . '/zz-stray-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($stray, "<?php\nreturn 42;\n");

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('does not return a Recipe');

            Cookbook::all();
        } finally {
            unlink($stray);
        }
    }
}
