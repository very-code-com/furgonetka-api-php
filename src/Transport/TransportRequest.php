<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Transport;

use VeryCodeCom\FurgonetkaApi\Internal\Http\Redactor;

/**
 * A transport-agnostic HTTP request: everything {@see CurlTransport} needs to make one call.
 *
 * Furgonetka needs more verbs and body kinds than a plain POST client: `PUT` for the asynchronous
 * commands, `DELETE` with a JSON body for bulk deletion and cancellation, and
 * `application/x-www-form-urlencoded` for the OAuth endpoints (see {@see self::form()}). The
 * body is a plain string, so JSON, form data and text all travel the same way.
 *
 * The request is validated on construction, because it is built from values that are not always
 * under the caller's control (a download URL comes out of an API response): the URL must be
 * http(s), and header names and values must not be able to smuggle in a second header.
 */
final class TransportRequest
{
    public const METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /**
     * @param non-empty-string      $method  One of {@see self::METHODS}, upper case.
     * @param string|null           $body    Sent as is. A `GET` cannot have one.
     * @param array<string, string> $headers Request headers, keyed by header name.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'GET',
        #[\SensitiveParameter]
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly int $timeout = 30,
        public readonly int $connectTimeout = 10,
    ) {
        if (preg_match('#^https?://[^\s/?\#]+#i', $url) !== 1) {
            throw new \InvalidArgumentException('TransportRequest: the URL must be an absolute http(s) URL.');
        }

        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'TransportRequest: unsupported method "%s", expected one of %s.',
                $method,
                implode(', ', self::METHODS),
            ));
        }

        if ($method === 'GET' && $body !== null) {
            throw new \InvalidArgumentException('TransportRequest: a GET request cannot have a body.');
        }

        if ($timeout < 1 || $connectTimeout < 1) {
            throw new \InvalidArgumentException('TransportRequest: timeouts must be at least 1 second.');
        }

        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', (string) $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('TransportRequest: "%s" is not a valid header name.', $name));
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf('TransportRequest: the value of header "%s" contains a control character.', $name));
            }
        }
    }

    /**
     * A `POST` with a form-encoded body, as the OAuth token and 2FA endpoints expect.
     *
     * Fields with a null value are left out. Any `Content-Type` in `$headers` is replaced.
     *
     * @param array<string, scalar|null> $fields
     * @param array<string, string>      $headers
     */
    public static function form(
        string $url,
        #[\SensitiveParameter]
        array $fields,
        array $headers = [],
        int $timeout = 30,
        int $connectTimeout = 10,
    ): self {
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'content-type') {
                unset($headers[$name]);
            }
        }

        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return new self(
            url: $url,
            method: 'POST',
            body: http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            headers: $headers,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
        );
    }

    /** A request header by name (case-insensitive), or null when absent. */
    public function header(string $name): ?string
    {
        $name = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * `METHOD scheme://host/path`, without the query string. This is what goes into error
     * messages and logs: a query can carry a signed download link, and the path is enough to
     * tell which call failed.
     */
    public function describe(): string
    {
        $position = strcspn($this->url, '?#');

        return $this->method . ' ' . substr($this->url, 0, $position);
    }

    /**
     * The same request with its credentials masked, safe to put in a log or an exception
     * message: the `Authorization` header keeps only its scheme, and the secret fields of a form
     * or JSON body (password, client secret, refresh token, authorization code, 2FA code) are
     * replaced. Everything else is unchanged.
     *
     * A JSON payload that happens to contain a field called `code` is masked too. That errs on
     * the side of hiding, which is the right side for something meant for logs.
     *
     * The body of a `multipart/form-data` upload is not shown at all: it is a whole file (an
     * invoice, a photo) that no masking of a few keys could make safe, so only its size is kept.
     */
    public function redacted(): self
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[$name] = Redactor::header((string) $name, $value);
        }

        $type = $this->header('content-type') ?? '';
        $body = match (true) {
            $this->body === null => null,
            stripos($type, 'multipart/') === 0 => sprintf('[multipart body withheld: %d bytes]', strlen($this->body)),
            default => Redactor::body($this->body, $type, Redactor::REQUEST_KEYS),
        };

        return new self(
            url: $this->url,
            method: $this->method,
            body: $body,
            headers: $headers,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
        );
    }

    /**
     * `var_dump()` and `print_r()` show the redacted request, so debugging a client with a dump
     * cannot print a token.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $redacted = $this->redacted();

        return [
            'url' => $redacted->url,
            'method' => $redacted->method,
            'body' => $redacted->body,
            'headers' => $redacted->headers,
            'timeout' => $redacted->timeout,
            'connectTimeout' => $redacted->connectTimeout,
        ];
    }
}
