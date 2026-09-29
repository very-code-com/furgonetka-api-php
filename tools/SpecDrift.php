<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tools;

use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\EndpointAuth;

/**
 * Compares the operation registry ({@see Endpoint}) with Furgonetka's published OpenAPI
 * specification and lists every place where they have drifted apart.
 *
 * Furgonetka changes its API every few days, and an unknown media type version is silently
 * ignored by the server, so the library cannot find out from a failing request that an operation
 * moved to a newer version. This can: run weekly against the current specification.
 *
 * For each registered operation it reports:
 *  - the operation is gone from the specification (removed, or renamed);
 *  - the operation is marked `deprecated`;
 *  - a newer media type version exists than the one registered, or the registered one is not
 *    offered any more;
 *  - the operation started or stopped taking a request body;
 *  - the authentication changed (a public operation now asks for a user token, or the reverse).
 *
 * New operations that are not registered are not drift: most of the specification is out of scope
 * on purpose. {@see self::unregistered()} lists them for whoever wants to look.
 *
 * The specification is read as decoded JSON. Furgonetka publishes YAML; convert it first, for
 * instance with `yq -o=json`. Only `$ref`s into `#/components/requestBodies` and
 * `#/components/responses` are followed, because those are the only ones that hide a media type.
 */
final class SpecDrift
{
    private const USER_SCHEME = 'bearer-token-for-user';
    private const HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch'];

    /** @param array<string, mixed> $spec The decoded OpenAPI document. */
    public function __construct(private readonly array $spec)
    {
    }

    /**
     * Every difference found, one line each, prefixed with the operation.
     *
     * @return list<string> Empty when the registry and the specification agree.
     */
    public function check(): array
    {
        $findings = [];

        foreach (Endpoint::cases() as $endpoint) {
            foreach ($this->checkOne($endpoint) as $finding) {
                $findings[] = $endpoint->value . ': ' . $finding;
            }
        }

        return $findings;
    }

    /**
     * Operations in the specification that the registry does not have, as `METHOD /path`. Not
     * an error: the library covers a part of the API.
     *
     * @return list<string>
     */
    public function unregistered(): array
    {
        $registered = array_map(static fn (Endpoint $e): string => $e->value, Endpoint::cases());
        $found = [];

        foreach ($this->paths() as $path => $item) {
            foreach (self::HTTP_METHODS as $method) {
                if (isset($item[$method]) && is_array($item[$method])) {
                    $key = strtoupper($method) . ' ' . $path;

                    if (!in_array($key, $registered, true)) {
                        $found[] = $key;
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function checkOne(Endpoint $endpoint): array
    {
        $operation = $this->paths()[$endpoint->pathTemplate()][strtolower($endpoint->method())] ?? null;

        if (!is_array($operation)) {
            return ['is not in the specification any more (removed or renamed).'];
        }

        /** @var array<string, mixed> $operation */
        $findings = [];

        if (($operation['deprecated'] ?? false) === true) {
            $findings[] = 'is marked deprecated in the specification.';
        }

        // The specification's PHP backend writes an empty map as [], so "no body" shows up as
        // `requestBody: []` on several GET operations.
        $body = $operation['requestBody'] ?? null;
        $hasBody = is_array($body) && $body !== [];

        $registryBody = $endpoint->hasBody() || $endpoint->isUpload();

        if ($hasBody !== $registryBody) {
            $findings[] = $hasBody
                ? 'now takes a request body, the registry says it takes none.'
                : 'no longer takes a request body, the registry says it does.';
        }

        $userToken = in_array(self::USER_SCHEME, $this->securitySchemes($operation), true);
        $registryToken = $endpoint->auth() === EndpointAuth::User;

        // The registry sends the token to a few operations the specification lists as public, on
        // purpose ({@see Endpoint::sendsTokenBeyondSpec()}). That is not drift; the reverse still is.
        if ($userToken !== $registryToken && !($registryToken && !$userToken && $endpoint->sendsTokenBeyondSpec())) {
            $findings[] = $userToken
                ? 'now needs a user token, the registry says it is public.'
                : 'no longer needs a user token (schemes: ' . (implode(', ', $this->securitySchemes($operation)) ?: 'none') . '), the registry says it does.';
        }

        return [...$findings, ...$this->versionFindings($endpoint, $this->versions($operation))];
    }

    /**
     * @param list<int> $versions Versions the specification offers for the operation.
     * @return list<string>
     */
    private function versionFindings(Endpoint $endpoint, array $versions): array
    {
        $registered = $endpoint->version();
        $offered = $versions === [] ? 'none' : 'v' . implode(', v', $versions);

        if ($registered === null) {
            return $versions === []
                ? []
                : ["now offers versioned media types ({$offered}), the registry sends plain application/json."];
        }

        if ($versions === []) {
            return ["offers no versioned media type any more, the registry sends v{$registered}."];
        }

        if (max($versions) > $registered) {
            return ['has a newer version: v' . max($versions) . " is offered, the registry sends v{$registered}."];
        }

        if (!in_array($registered, $versions, true)) {
            return ["does not offer v{$registered} any more (offered: {$offered})."];
        }

        return [];
    }

    /**
     * Versions found in the media types of the request body and of every response.
     *
     * @param array<string, mixed> $operation
     * @return list<int>
     */
    private function versions(array $operation): array
    {
        $mediaTypes = [];

        array_push($mediaTypes, ...$this->mediaTypes($operation['requestBody'] ?? null, 'requestBodies'));

        $responses = $operation['responses'] ?? [];

        foreach (is_array($responses) ? $responses : [] as $response) {
            array_push($mediaTypes, ...$this->mediaTypes($response, 'responses'));
        }

        $versions = [];

        foreach ($mediaTypes as $mediaType) {
            if (preg_match('#^application/vnd\.furgonetka\.v(\d+)\+json#', $mediaType, $match) === 1) {
                $versions[(int) $match[1]] = (int) $match[1];
            }
        }

        sort($versions);

        return $versions;
    }

    /**
     * The media types of a request body or a response, following a reference into the
     * components when it is one.
     *
     * @return list<string>
     */
    private function mediaTypes(mixed $node, string $componentSection): array
    {
        if (is_array($node) && isset($node['$ref']) && is_string($node['$ref'])) {
            $prefix = '#/components/' . $componentSection . '/';

            if (!str_starts_with($node['$ref'], $prefix)) {
                return [];
            }

            $components = $this->spec['components'] ?? [];
            $section = is_array($components) ? ($components[$componentSection] ?? []) : [];
            $node = is_array($section) ? ($section[substr($node['$ref'], strlen($prefix))] ?? null) : null;
        }

        $content = is_array($node) ? ($node['content'] ?? null) : null;

        return is_array($content) ? array_map('strval', array_keys($content)) : [];
    }

    /**
     * Names of the security schemes an operation lists, across all its alternatives.
     *
     * @param array<string, mixed> $operation
     * @return list<string>
     */
    private function securitySchemes(array $operation): array
    {
        $names = [];
        $security = $operation['security'] ?? [];

        foreach (is_array($security) ? $security : [] as $alternative) {
            foreach (is_array($alternative) ? array_keys($alternative) : [] as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, array<string, mixed>> Path items that are objects; references to other files are skipped. */
    private function paths(): array
    {
        $paths = $this->spec['paths'] ?? [];
        $items = [];

        foreach (is_array($paths) ? $paths : [] as $path => $item) {
            if (is_array($item) && !isset($item['$ref'])) {
                /** @var array<string, mixed> $item */
                $items[(string) $path] = $item;
            }
        }

        return $items;
    }
}
