<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Http;

use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;

/**
 * Turns an {@see Endpoint} and its arguments into a {@see TransportRequest}: URL, headers, query
 * string and JSON body.
 *
 * Every header that depends on the operation comes from the registry, so no call site can send
 * the wrong version:
 *  - `Accept` and, when there is a body, `Content-Type` carry the operation's media type;
 *  - `X-Language` is always sent (English unless the config asks for Polish);
 *  - `X-Company-Id` selects the company on multi-company accounts, on authenticated calls;
 *  - `Authorization: Bearer` is sent only to operations that take one. A token is never sent to
 *    a public operation, even when the caller passes one.
 *
 * @internal
 */
final class RequestBuilder
{
    public function __construct(private readonly FurgonetkaApiConfig $config)
    {
    }

    /**
     * @param array<string, string|int>                              $pathParameters Values for the `{placeholders}` of the path, by name.
     * @param array<string, scalar|array<mixed>|null>                $query          Query parameters; nulls are left out, booleans are sent as `true` and `false`.
     * @param array<string, mixed>|null                              $body           The JSON body; required for an operation that takes one, refused for one that does not.
     * @param string|null                                            $accessToken    The user's bearer token; required for an authenticated operation.
     *
     * @throws \InvalidArgumentException when a path parameter is missing, unknown or empty, or the body cannot be encoded.
     * @throws \LogicException when the arguments do not fit the operation (no token for an authenticated call, a body for a call without one).
     */
    public function build(
        Endpoint $endpoint,
        array $pathParameters = [],
        array $query = [],
        #[\SensitiveParameter]
        ?array $body = null,
        #[\SensitiveParameter]
        ?string $accessToken = null,
    ): TransportRequest {
        if ($endpoint->hasBody() && $body === null) {
            throw new \LogicException("{$endpoint->value} takes a JSON body, none was given.");
        }

        if (!$endpoint->hasBody() && $body !== null) {
            throw new \LogicException("{$endpoint->value} takes no body, but one was given.");
        }

        $url = $this->config->url($this->path($endpoint, $pathParameters));
        $queryString = $this->queryString($query);

        $headers = [
            'Accept' => $endpoint->accept(),
            FurgonetkaApiConfig::HEADER_LANGUAGE => $this->config->language->value,
        ];

        $contentType = $endpoint->contentType();
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        if ($endpoint->auth() === EndpointAuth::User) {
            if ($accessToken === null || $accessToken === '') {
                throw new \LogicException("{$endpoint->value} needs an access token.");
            }

            $headers['Authorization'] = 'Bearer ' . $accessToken;

            if ($this->config->companyId !== null) {
                $headers[FurgonetkaApiConfig::HEADER_COMPANY_ID] = (string) $this->config->companyId;
            }
        }

        return new TransportRequest(
            url: $queryString === '' ? $url : $url . '?' . $queryString,
            method: $endpoint->method(),
            body: $body === null ? null : $this->encode($endpoint, $body),
            headers: $headers,
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
        );
    }

    /**
     * The request of a file upload: `multipart/form-data` with the file and, when given, its
     * `type`. The boundary is random per request and checked against the content, so a file that
     * happens to contain it is not cut short.
     *
     * @param array<string, string|int> $pathParameters
     */
    public function buildUpload(
        Endpoint $endpoint,
        array $pathParameters,
        #[\SensitiveParameter]
        string $contents,
        string $filename,
        string $contentType,
        ?string $type,
        #[\SensitiveParameter]
        string $accessToken,
    ): TransportRequest {
        if (!$endpoint->isUpload()) {
            throw new \LogicException("{$endpoint->value} is not a file upload.");
        }

        if ($accessToken === '') {
            throw new \LogicException("{$endpoint->value} needs an access token.");
        }

        // A quote, a backslash or a control character would end the quoted filename early and could
        // add headers to the part, so the name is reduced to what a header value can safely hold.
        // A character at a time when the name is UTF-8, so "łó" becomes two underscores, not four.
        $pattern = mb_check_encoding($filename, 'UTF-8') ? '#[^\x20-\x7E]|["\\\\/]#Du' : '#[^\x20-\x7E]|["\\\\/]#D';
        $safeName = preg_replace($pattern, '_', $filename) ?? '';
        $safeName = trim($safeName) !== '' ? $safeName : 'file';

        if (preg_match('#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+$#D', $contentType) !== 1) {
            throw new \InvalidArgumentException('The file\'s content type must look like "application/pdf".');
        }

        do {
            $boundary = 'furgonetka-' . bin2hex(random_bytes(12));
        } while (str_contains($contents, $boundary));

        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$safeName}\"\r\n"
            . "Content-Type: {$contentType}\r\n\r\n"
            . $contents . "\r\n";

        if ($type !== null && $type !== '') {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n{$type}\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $headers = [
            'Accept' => $endpoint->accept(),
            FurgonetkaApiConfig::HEADER_LANGUAGE => $this->config->language->value,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Authorization' => 'Bearer ' . $accessToken,
        ];

        if ($this->config->companyId !== null) {
            $headers[FurgonetkaApiConfig::HEADER_COMPANY_ID] = (string) $this->config->companyId;
        }

        return new TransportRequest(
            url: $this->config->url($this->path($endpoint, $pathParameters)),
            method: $endpoint->method(),
            body: $body,
            headers: $headers,
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
        );
    }

    /**
     * The path with every placeholder replaced by its percent-encoded value.
     *
     * @param array<string, string|int> $values
     */
    private function path(Endpoint $endpoint, array $values): string
    {
        $expected = $endpoint->pathParameters();
        $unknown = array_diff(array_keys($values), $expected);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                '%s has no path parameter "%s"; it takes: %s.',
                $endpoint->value,
                (string) array_key_first(array_flip($unknown)),
                $expected === [] ? 'none' : implode(', ', $expected),
            ));
        }

        $path = $endpoint->pathTemplate();

        foreach ($expected as $name) {
            $value = trim((string) ($values[$name] ?? ''));

            if ($value === '') {
                throw new \InvalidArgumentException("{$endpoint->value} needs the path parameter \"{$name}\".");
            }

            $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
        }

        return $path;
    }

    /**
     * @param array<string, scalar|array<mixed>|null> $query
     */
    private function queryString(array $query): string
    {
        return http_build_query(self::normalise($query), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Drop nulls and empty arrays and spell booleans the way the specification does. PHP would
     * send `true` as `1`, which the documented boolean parameters (`original_services=false`) do
     * not promise to accept.
     *
     * Lists and maps are left to `http_build_query()`, which writes `filters[service][0]=dpd`.
     * Whether the API also wants `filters[service][]=dpd` is open check W3 in the plan; a PHP
     * backend reads both the same way.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function normalise(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $value = self::normalise($value);

                if ($value === []) {
                    continue;
                }
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf('Query parameter "%s" must be a scalar or an array, %s given.', (string) $key, get_debug_type($value)));
            }

            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encode(Endpoint $endpoint, #[\SensitiveParameter] array $body): string
    {
        // No JSON_THROW_ON_ERROR: the JsonException of json_encode() would carry the whole body
        // (names and addresses of real people) in the arguments of its stack trace.
        $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \InvalidArgumentException("The body of {$endpoint->value} cannot be encoded as JSON: " . json_last_error_msg() . '.');
        }

        return $json;
    }
}
