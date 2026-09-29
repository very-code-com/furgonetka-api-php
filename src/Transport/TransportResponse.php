<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Transport;

use VeryCodeCom\FurgonetkaApi\Internal\Http\Redactor;

/**
 * The raw HTTP response returned by a {@see TransportInterface}.
 *
 * The body is a byte string and is never altered, so it carries a JSON document, a PDF label or
 * a ZPL/EPL text label equally. Headers travel with it because several answers depend on them:
 * the content type of a label (PDF or text), the `X-RateLimit-*` counters on every response and
 * the cool-down on a throttled request.
 */
final class TransportResponse
{
    /** @var array<string, string> Response headers, keyed by lower-cased header name. */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $statusCode,
        #[\SensitiveParameter]
        public readonly string $body,
        array $headers = [],
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = $value;
        }

        $this->headers = $normalised;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** A response header by name (case-insensitive), or null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The `Content-Type` without its parameters, e.g. `application/json`, lower-cased. */
    public function contentType(): string
    {
        $contentType = $this->header('content-type') ?? '';
        $semicolon = strpos($contentType, ';');

        return strtolower(trim($semicolon === false ? $contentType : substr($contentType, 0, $semicolon)));
    }

    /**
     * True when the response says it is JSON. Furgonetka answers with plain `application/json`
     * even though requests use the `application/vnd.furgonetka.vN+json` media type; a `+json`
     * suffix is accepted too so a future change does not turn every answer into a parse error.
     */
    public function isJson(): bool
    {
        $type = $this->contentType();

        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    /** True when the body is a PDF, either by `Content-Type` or by its magic bytes. */
    public function isPdf(): bool
    {
        return $this->contentType() === 'application/pdf' || str_starts_with($this->body, '%PDF-');
    }

    /**
     * Seconds to wait before the request limit resets, or null when there is nothing to wait for.
     *
     * Furgonetka documents the non-standard `X-RateLimit-Retry-After` and sends it on every
     * response, with the value 0 while the limit is not exceeded; that 0 means "no wait" and is
     * returned as null. The standard `Retry-After` is read as a fallback, and only when it is a
     * number of seconds (an HTTP date is valid there but is not guessed at).
     */
    public function retryAfterSeconds(): ?int
    {
        foreach (['x-ratelimit-retry-after', 'retry-after'] as $name) {
            $value = trim($this->header($name) ?? '');

            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * The same response with the tokens in its body masked, for logs and debug reports. Only the
     * fields that are secrets (`access_token`, `refresh_token`) change; an error body keeps its
     * `code` so it stays readable.
     */
    public function redacted(): self
    {
        return new self($this->statusCode, Redactor::json($this->body, Redactor::RESPONSE_KEYS), $this->headers);
    }

    /**
     * `var_dump()` and `print_r()` show the redacted response, because the token endpoint's
     * answer holds the tokens in its body.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $redacted = $this->redacted();

        return [
            'statusCode' => $redacted->statusCode,
            'body' => $redacted->body,
            'headers' => $redacted->headers,
        ];
    }
}
