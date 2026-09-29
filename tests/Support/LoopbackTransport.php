<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Sends requests meant for Furgonetka to a server on the loopback interface instead, keeping the
 * path, query, headers and body. The config has fixed hosts (there is no switch to point it at a
 * test server, on purpose), so this is how a test runs real cURL against {@see LocalServer}.
 */
final class LoopbackTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly string $baseUrl,
    ) {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $parts = parse_url($request->url);
        $url = rtrim($this->baseUrl, '/') . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return $this->inner->send(new TransportRequest(
            url: $url,
            method: $request->method,
            body: $request->body,
            headers: $request->headers,
            timeout: $request->timeout,
            connectTimeout: $request->connectTimeout,
        ));
    }
}
