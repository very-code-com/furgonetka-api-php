<?php

declare(strict_types=1);

/**
 * Runs the recipes of the cookbook.
 *
 *   php examples/recipes/run.php                     # the list
 *   php examples/recipes/run.php shipments/dpd-cod   # one recipe, in full
 *   php examples/recipes/run.php all                 # every recipe, one line each
 *   php examples/recipes/run.php shipments/          # every recipe whose name starts so
 *
 * Nothing is created or ordered. A shipment recipe is validated (locally and by Furgonetka) and priced; a
 * query recipe makes read-only calls. Sign in first (see examples/README.md), on the sandbox.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/Scenario.php';
require_once __DIR__ . '/Recipe.php';
require_once __DIR__ . '/Outcome.php';
require_once __DIR__ . '/Cookbook.php';

use VeryCodeCom\FurgonetkaApi\Examples\Cookbook;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;

exampleRun(static function (): void {
    $recipes = Cookbook::all();
    $wanted = exampleArgument(1);

    if ($wanted === null) {
        exampleHeading(count($recipes) . ' recipes');

        foreach ($recipes as $name => $recipe) {
            printf("%-38s %s\n", $name, $recipe->title);
        }

        echo "\nRun one with its name, a group with a prefix such as shipments/, or all.\n";

        return;
    }

    $chosen = $wanted === 'all' ? $recipes : array_filter($recipes, static fn (string $name): bool => $name === $wanted || str_starts_with($name, $wanted), ARRAY_FILTER_USE_KEY);

    if ($chosen === []) {
        throw new \RuntimeException("No recipe is called or starts with \"{$wanted}\". Run without arguments for the list.");
    }

    $client = exampleClient();
    $full = count($chosen) === 1;
    $unexpected = 0;

    foreach ($chosen as $name => $recipe) {
        try {
            $outcome = Cookbook::check($recipe, $client);
        } catch (FurgonetkaApiException | \RuntimeException | \InvalidArgumentException $e) {
            // One recipe that cannot run (no contract, a server error) must not hide the results of the others.
            printf("%-38s %-9s %s\n", $name, 'ERROR', mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 100));
            ++$unexpected;

            continue;
        }

        $fine = $outcome->isAsExpected($recipe);
        $unexpected += $fine ? 0 : 1;

        if ($full) {
            exampleHeading($recipe->title);
            echo wordwrap($recipe->shows, 100) . PHP_EOL;

            if ($recipe->isShipment()) {
                echo PHP_EOL . 'Carrier: ' . ($recipe->carrier->value ?? '') . PHP_EOL;
                echo json_encode($outcome->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                echo PHP_EOL . 'Local errors:   ' . ($outcome->local === [] ? 'none' : implode(' | ', $outcome->local)) . PHP_EOL;
                echo 'Local warnings: ' . ($outcome->warnings === [] ? 'none' : implode(' | ', $outcome->warnings)) . PHP_EOL;
                echo 'Furgonetka:     ' . ($outcome->remote === [] ? 'accepts it' : 'refuses: ' . implode(' | ', $outcome->remote)) . PHP_EOL;
                echo 'Price:          ' . ($outcome->priced ? sprintf('%.2f PLN gross', (float) $outcome->price) : ($outcome->priceErrors !== [] ? 'not available: ' . implode(' | ', $outcome->priceErrors) : 'not asked')) . PHP_EOL;
            } else {
                echo PHP_EOL . implode(PHP_EOL, $outcome->lines) . PHP_EOL;
            }

            echo PHP_EOL . ($fine ? "As the recipe says.\n" : "NOT as the recipe says: Furgonetka changed a rule, or the account differs.\n");

            continue;
        }

        if ($recipe->isShipment()) {
            printf("%-38s %-9s %-46s %s\n", $name, $recipe->carrier->value ?? '', $outcome->remote === [] ? ($outcome->priced ? sprintf('accepted, %.2f PLN', (float) $outcome->price) : 'accepted') : 'refused: ' . implode(', ', $outcome->remoteCodes), $fine ? '' : '<-- UNEXPECTED');
        } else {
            printf("%-38s %-9s %-46s %s\n", $name, 'query', count($outcome->lines) . ' line(s)', $fine ? '' : '<-- UNEXPECTED');
        }
    }

    if ($unexpected > 0) {
        fwrite(STDERR, "\n{$unexpected} recipe(s) did not behave as written.\n");
        exit(1);
    }
});
