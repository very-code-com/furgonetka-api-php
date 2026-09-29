<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Examples;

use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

/**
 * Finds the recipes and checks them. Used by `run.php` and by the integration test.
 */
final class Cookbook
{
    /** Files of this directory that are not recipes. */
    private const SUPPORT = ['Scenario.php', 'Recipe.php', 'Cookbook.php', 'Outcome.php', 'run.php'];

    /**
     * Every recipe, by name: the path below `recipes/` without `.php`, for instance `shipments/dpd-cod`.
     *
     * @return array<string, Recipe>
     */
    public static function all(): array
    {
        $recipes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || in_array($file->getFilename(), self::SUPPORT, true)) {
                continue;
            }

            $recipe = require $file->getPathname();

            if (!$recipe instanceof Recipe) {
                throw new \LogicException($file->getPathname() . ' does not return a Recipe.');
            }

            $recipes[substr(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(__DIR__) + 1)), 0, -4)] = $recipe;
        }

        ksort($recipes);

        return $recipes;
    }

    /**
     * Runs a recipe: a shipment is validated locally and by Furgonetka and priced (nothing is created); a
     * query is executed.
     *
     * @throws \VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException when a request fails for a reason other than the data.
     * @throws \InvalidArgumentException when a request object of the recipe refuses its own values.
     * @throws \RuntimeException when the account lacks what the recipe needs (a contract, a point, a shipment).
     */
    public static function check(Recipe $recipe, FurgonetkaApiClient $client, bool $price = true): Outcome
    {
        if (!$recipe->isShipment()) {
            return new Outcome(lines: $recipe->lines($client));
        }

        $request = $recipe->shipmentRequest($client);
        $local = $client->validateLocally($request, $recipe->carrier);
        $warnings = $client->warningsFor($request, $recipe->carrier);
        $errors = $client->validateRemotely($request);
        $remote = array_map(static fn ($error): string => (string) $error->code . ' ' . (string) $error->path, $errors);
        $remoteCodes = array_map(static fn ($error): string => (string) $error->code, $errors);
        $quote = null;

        if ($price && $remote === []) {
            $quote = $client->calculatePrices($request, PriceScope::serviceIds($request->serviceId))->forServiceId($request->serviceId);
        }

        return new Outcome(
            request: $request->toArray(),
            local: $local,
            warnings: $warnings,
            remote: $remote,
            remoteCodes: $remoteCodes,
            priced: $quote !== null && $quote->available,
            price: $quote?->pricing?->priceGross,
            priceErrors: $quote !== null && !$quote->available ? array_map('strval', $quote->errors) : [],
        );
    }
}
