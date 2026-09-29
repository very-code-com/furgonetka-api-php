<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Tools\SpecDrift;

require_once __DIR__ . '/../../../tools/SpecDrift.php';

/**
 * The drift check against the snapshot of Furgonetka's specification from 2026-09-28 (changelog
 * v1.0.118), reduced to what the tool reads. The clean run is the acceptance test of the registry;
 * every other test edits the snapshot the way Furgonetka's own changes would and expects the
 * finding, named after the operation.
 */
final class SpecDriftTest extends TestCase
{
    private const SNAPSHOT = __DIR__ . '/../../Fixtures/spec/openapi-2026-09-28.json';

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents(self::SNAPSHOT), true, flags: JSON_THROW_ON_ERROR);

        return $spec;
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<string>
     */
    private function findings(array $spec): array
    {
        return (new SpecDrift($spec))->check();
    }

    /**
     * Change one operation of the snapshot in place.
     *
     * @param array<string, mixed> $spec
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function edited(array $spec, string $path, string $method, callable $change): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $paths */
        $paths = $spec['paths'];
        $paths[$path][$method] = $change($paths[$path][$method]);
        $spec['paths'] = $paths;

        return $spec;
    }

    public function testTheRegistryMatchesTheSnapshotOf2026_09_28(): void
    {
        self::assertSame([], $this->findings($this->snapshot()));
    }

    public function testTheSnapshotIsTheWholeSpecificationNotAnExcerptOfOurOperations(): void
    {
        $unregistered = (new SpecDrift($this->snapshot()))->unregistered();

        self::assertContains('DELETE /account', $unregistered);
        self::assertContains('GET /account/notifications', $unregistered, 'Out of scope: the list of notifications of the panel.');
        self::assertNotContains('POST /packages', $unregistered);
        self::assertGreaterThan(170, count($unregistered) + count(Endpoint::cases()), 'The snapshot holds every operation of the specification (179), not only ours.');
    }

    public function testEveryRegisteredOperationIsInTheSnapshot(): void
    {
        $spec = $this->snapshot();

        foreach (Endpoint::cases() as $endpoint) {
            /** @var array<string, array<string, mixed>> $paths */
            $paths = $spec['paths'];
            self::assertArrayHasKey(strtolower($endpoint->method()), $paths[$endpoint->pathTemplate()] ?? [], $endpoint->value);
        }
    }

    public function testNoRegisteredOperationIsDeprecatedInTheSnapshot(): void
    {
        $spec = $this->snapshot();

        foreach (Endpoint::cases() as $endpoint) {
            /** @var array<string, array<string, array<string, mixed>>> $paths */
            $paths = $spec['paths'];
            self::assertNotTrue($paths[$endpoint->pathTemplate()][strtolower($endpoint->method())]['deprecated'] ?? false, $endpoint->value);
        }
    }

    // -- What Furgonetka could change ---------------------------------------------

    public function testARemovedOperationIsReportedByName(): void
    {
        $spec = $this->snapshot();
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $spec['paths'];
        unset($paths['/regulations']['get']);
        $spec['paths'] = $paths;

        self::assertSame(['GET /regulations: is not in the specification any more (removed or renamed).'], $this->findings($spec));
    }

    public function testARemovedPathIsReportedForEveryOperationOnIt(): void
    {
        $spec = $this->snapshot();
        /** @var array<string, mixed> $paths */
        $paths = $spec['paths'];
        unset($paths['/packages/regulations']);
        $spec['paths'] = $paths;

        self::assertSame(['GET /packages/regulations: is not in the specification any more (removed or renamed).'], $this->findings($spec));
    }

    public function testADeprecatedOperationIsReported(): void
    {
        $spec = $this->edited($this->snapshot(), '/packages/protocol', 'post', static fn (array $o): array => ['deprecated' => true] + $o);

        self::assertSame(['POST /packages/protocol: is marked deprecated in the specification.'], $this->findings($spec));
    }

    public function testANewerVersionIsReportedEvenWhenItIsReachedThroughAReference(): void
    {
        // POST /packages takes its request body from a shared component, so the new media type
        // shows up there, not on the operation.
        $spec = $this->snapshot();
        /** @var array<string, array<string, array<string, array<string, mixed>>>> $components */
        $components = $spec['components'];
        $components['requestBodies']['PackageParams']['content']['application/vnd.furgonetka.v3+json'] = [];
        $spec['components'] = $components;

        $findings = $this->findings($spec);

        self::assertContains('POST /packages: has a newer version: v3 is offered, the registry sends v2.', $findings);
    }

    public function testANewerVersionOnAResponseIsReported(): void
    {
        $spec = $this->edited($this->snapshot(), '/packages/{package_id}/label', 'get', static function (array $o): array {
            $o['responses']['200']['content']['application/vnd.furgonetka.v2+json'] = [];

            return $o;
        });

        self::assertSame(['GET /packages/{package_id}/label: has a newer version: v2 is offered, the registry sends v1.'], $this->findings($spec));
    }

    public function testAVersionThatIsNotOfferedAnyMoreIsReported(): void
    {
        // A v2 operation of which only v1 is left, as if v2 had been withdrawn.
        $spec = $this->snapshot();
        /** @var array<string, array<string, array<string, array<string, mixed>>>> $components */
        $components = $spec['components'];
        unset($components['requestBodies']['PackageParams']['content']['application/vnd.furgonetka.v2+json']);
        $spec['components'] = $components;
        $spec = $this->edited($spec, '/packages', 'post', static function (array $o): array {
            unset($o['responses']['200']['content']['application/vnd.furgonetka.v2+json']);

            return $o;
        });

        // The request body component is shared by POST /packages/validate and PUT /create-package-command/{uuid}, so all three lose v2.
        self::assertSame([
            'POST /packages/validate: does not offer v2 any more (offered: v1).',
            'POST /packages: does not offer v2 any more (offered: v1).',
            'PUT /create-package-command/{uuid}: does not offer v2 any more (offered: v1).',
        ], $this->findings($spec));
    }

    public function testAnOperationThatLostAllVersionedMediaTypesIsReported(): void
    {
        $spec = $this->edited($this->snapshot(), '/account/services', 'get', static function (array $o): array {
            $o['responses']['200']['content'] = ['application/json' => []];
            unset($o['responses']['401']);

            return $o;
        });

        self::assertSame(['GET /account/services: offers no versioned media type any more, the registry sends v1.'], $this->findings($spec));
    }

    public function testAnUnversionedOperationThatGainedVersionsIsReported(): void
    {
        $spec = $this->edited($this->snapshot(), '/account/companies', 'get', static function (array $o): array {
            $o['responses']['200']['content']['application/vnd.furgonetka.v1+json'] = [];

            return $o;
        });

        self::assertSame(['GET /account/companies: now offers versioned media types (v1), the registry sends plain application/json.'], $this->findings($spec));
    }

    public function testABodyThatAppearedOrDisappearedIsReported(): void
    {
        $gained = $this->edited($this->snapshot(), '/account/services', 'get', static fn (array $o): array => $o + ['requestBody' => ['content' => ['application/vnd.furgonetka.v1+json' => []]]]);
        $lost = $this->edited($this->snapshot(), '/regulations', 'post', static function (array $o): array {
            unset($o['requestBody']);

            return $o;
        });

        self::assertSame(['GET /account/services: now takes a request body, the registry says it takes none.'], $this->findings($gained));
        self::assertSame(['POST /regulations: no longer takes a request body, the registry says it does.'], $this->findings($lost));
    }

    public function testAnEmptyRequestBodyListIsNoBody(): void
    {
        // The specification's PHP backend writes an empty map as [], and does so on real GET operations.
        $spec = $this->edited($this->snapshot(), '/account/services', 'get', static fn (array $o): array => ['requestBody' => []] + $o);

        self::assertSame([], $this->findings($spec));
    }

    public function testAChangeOfAuthenticationIsReported(): void
    {
        $nowPublic = $this->edited($this->snapshot(), '/account/services', 'get', static function (array $o): array {
            unset($o['security']);

            return $o;
        });
        $nowPrivate = $this->edited($this->snapshot(), '/configuration/dictionary', 'get', static fn (array $o): array => $o + ['security' => [['bearer-token-for-user' => []]]]);
        $otherScheme = $this->edited($this->snapshot(), '/account/services', 'get', static fn (array $o): array => ['security' => [['application-token' => []]]] + $o);

        self::assertSame(['GET /account/services: no longer needs a user token (schemes: none), the registry says it does.'], $this->findings($nowPublic));
        self::assertSame(['GET /configuration/dictionary: now needs a user token, the registry says it is public.'], $this->findings($nowPrivate));
        self::assertSame(['GET /account/services: no longer needs a user token (schemes: application-token), the registry says it does.'], $this->findings($otherScheme));
    }

    public function testSeveralFindingsAreAllReportedInRegistryOrder(): void
    {
        $spec = $this->edited($this->snapshot(), '/account/services', 'get', static function (array $o): array {
            $o['deprecated'] = true;
            unset($o['security']);

            return $o;
        });
        $spec = $this->edited($spec, '/regulations', 'get', static fn (array $o): array => ['deprecated' => true] + $o);

        self::assertSame([
            'GET /account/services: is marked deprecated in the specification.',
            'GET /account/services: no longer needs a user token (schemes: none), the registry says it does.',
            'GET /regulations: is marked deprecated in the specification.',
        ], $this->findings($spec));
    }

    public function testAReferenceToAnotherFileHidesNothingWeRegistered(): void
    {
        $spec = $this->snapshot();
        /** @var array<string, mixed> $paths */
        $paths = $spec['paths'];
        $paths['/finances/transfers'] = ['$ref' => 'https://api.furgonetka.pl/finances/api-documentation.yaml#/paths/~1finances~1transfers'];
        $spec['paths'] = $paths;

        self::assertSame([], $this->findings($spec));
        self::assertNotContains('GET /finances/transfers', (new SpecDrift($spec))->unregistered());
    }

    public function testAnEmptyDocumentReportsEveryOperation(): void
    {
        $findings = $this->findings(['openapi' => '3.0.0']);

        self::assertCount(count(Endpoint::cases()), $findings);
        self::assertStringContainsString('is not in the specification any more', $findings[0]);
    }

    // -- The command line ---------------------------------------------------------

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runTool(array $arguments): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available.');
        }

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../../tools/spec-drift.php', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }

    public function testTheCommandLineExitsCleanlyOnTheSnapshot(): void
    {
        [$exit, $output] = $this->runTool([self::SNAPSHOT]);

        self::assertSame(0, $exit);
        self::assertSame("No drift: every registered operation matches the specification.\n", $output);
    }

    public function testTheCommandLineFailsWithAnnotationsWhenTheSpecificationDrifted(): void
    {
        $spec = $this->edited($this->snapshot(), '/packages/protocol', 'post', static fn (array $o): array => ['deprecated' => true] + $o);
        $file = tempnam(sys_get_temp_dir(), 'furgonetka-drift-');
        self::assertIsString($file);
        file_put_contents($file, json_encode($spec, JSON_THROW_ON_ERROR));

        try {
            [$exit, $plain] = $this->runTool([$file]);
            [, $github] = $this->runTool([$file, '--github']);
            [, $withNew] = $this->runTool([$file, '--new']);
        } finally {
            unlink($file);
        }

        self::assertSame(1, $exit);
        self::assertStringContainsString("DRIFT POST /packages/protocol: is marked deprecated in the specification.\n", $plain);
        self::assertStringContainsString('1 finding(s)', $plain);
        self::assertStringContainsString('::error title=Furgonetka API drift::POST /packages/protocol: is marked deprecated', $github);
        self::assertStringContainsString("NEW   DELETE /account\n", $withNew);
    }

    public function testTheCommandLineRefusesBadUsage(): void
    {
        [$none, , $noneError] = $this->runTool([]);
        [$two, , ] = $this->runTool([self::SNAPSHOT, self::SNAPSHOT]);
        [$flag, , ] = $this->runTool([self::SNAPSHOT, '--nonsense']);
        [$missing, , $missingError] = $this->runTool(['/nonexistent/openapi.json']);

        self::assertSame(2, $none);
        self::assertStringContainsString('Usage:', $noneError);
        self::assertSame(2, $two);
        self::assertSame(2, $flag);
        self::assertSame(2, $missing);
        self::assertStringContainsString('Cannot read', $missingError);
    }

    public function testTheCommandLineExplainsThatYamlMustBeConvertedFirst(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'furgonetka-drift-');
        self::assertIsString($file);
        file_put_contents($file, "openapi: 3.0.0\npaths: {}\n");

        try {
            [$exit, , $errors] = $this->runTool([$file]);
        } finally {
            unlink($file);
        }

        self::assertSame(2, $exit);
        self::assertStringContainsString('yq -o=json', $errors);
    }
}
