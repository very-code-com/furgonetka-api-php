<?php

declare(strict_types=1);

/**
 * Compares the operation registry with Furgonetka's OpenAPI specification.
 *
 *   php tools/spec-drift.php <openapi.json> [--github] [--new]
 *
 * Furgonetka publishes the specification as YAML. Convert it first:
 *
 *   yq -o=json openapi.yaml > openapi.json
 *
 * The address of the YAML is the `spec-url` attribute of https://furgonetka.pl/api/rest (the file
 * name carries a hash that changes, so it cannot be hard-coded).
 *
 * Exit code: 0 when registry and specification agree, 1 when they have drifted, 2 on bad usage.
 *
 *   --github  print each finding as a GitHub Actions error annotation
 *   --new     also list operations of the specification that the registry does not have
 */

use VeryCodeCom\FurgonetkaApi\Tools\SpecDrift;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/SpecDrift.php';

$arguments = array_slice($argv, 1);
$flags = array_values(array_filter($arguments, static fn (string $a): bool => str_starts_with($a, '--')));
$files = array_values(array_filter($arguments, static fn (string $a): bool => !str_starts_with($a, '--')));

if (count($files) !== 1 || array_diff($flags, ['--github', '--new']) !== []) {
    fwrite(STDERR, "Usage: php tools/spec-drift.php <openapi.json> [--github] [--new]\n");
    exit(2);
}

$json = @file_get_contents($files[0]);

if ($json === false) {
    fwrite(STDERR, "Cannot read {$files[0]}\n");
    exit(2);
}

$spec = json_decode($json, true);

if (!is_array($spec) || !isset($spec['paths'])) {
    fwrite(STDERR, "{$files[0]} is not an OpenAPI document as JSON (no \"paths\"). Convert the YAML first: yq -o=json\n");
    exit(2);
}

/** @var array<string, mixed> $spec */
$drift = new SpecDrift($spec);
$findings = $drift->check();

foreach ($findings as $finding) {
    echo in_array('--github', $flags, true)
        ? '::error title=Furgonetka API drift::' . $finding . "\n"
        : 'DRIFT ' . $finding . "\n";
}

if (in_array('--new', $flags, true)) {
    foreach ($drift->unregistered() as $operation) {
        echo 'NEW   ' . $operation . "\n";
    }
}

echo $findings === []
    ? "No drift: every registered operation matches the specification.\n"
    : sprintf("%d finding(s): the registry no longer matches the specification.\n", count($findings));

exit($findings === [] ? 0 : 1);
