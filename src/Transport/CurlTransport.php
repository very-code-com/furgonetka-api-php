<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Transport;

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;

/**
 * Default cURL-based HTTP transport.
 *
 * No dependency beyond ext-curl, mirroring very-code-com/cargoboard-php. Response headers are
 * collected through a header callback rather than by prepending them to the body, so a PDF or
 * ZPL payload stays byte-exact.
 *
 * TLS certificates and host names are always verified, and redirects are never followed: the
 * request carries a bearer token that must not be replayed to whatever host a redirect names.
 */
final class CurlTransport implements TransportInterface
{
    public function send(TransportRequest $request): TransportResponse
    {
        $ch = curl_init($request->url);

        if ($ch === false) {
            throw new FurgonetkaApiTransportException('curl_init() failed. Is ext-curl installed?');
        }

        // The empty "Expect:" stops cURL from adding "Expect: 100-continue" to large bodies,
        // which some proxies in front of an API answer with an error instead of ignoring.
        $headers = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        $options = [
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $request->timeout,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeout,
            // An empty string means "every encoding cURL supports": the response is decoded
            // transparently, so a compressed list of shipments costs less to transfer.
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    // A new status line starts a new header block (after a 100 Continue or a
                    // proxy's CONNECT reply); headers of the earlier block do not apply.
                    $responseHeaders = [];
                } else {
                    $colon = strpos($line, ':');
                    if ($colon !== false) {
                        $name = strtolower(trim(substr($line, 0, $colon)));
                        $value = trim(substr($line, $colon + 1));

                        // A header repeated in the response is one list-valued header (RFC 9110).
                        $responseHeaders[$name] = isset($responseHeaders[$name])
                            ? $responseHeaders[$name] . ', ' . $value
                            : $value;
                    }
                }

                // cURL requires the number of bytes handled, not the number of bytes stored.
                return strlen($line);
            },
        ];

        // POST and PUT always send a body, even an empty one: without it cURL sends no
        // Content-Length and some proxies reject the request. DELETE sends one only when given
        // (bulk deletion and cancellation carry a list of ids).
        if ($request->body !== null || in_array($request->method, ['POST', 'PUT'], true)) {
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        curl_setopt_array($ch, $options);

        $body     = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errNo !== 0 || !is_string($body)) {
            throw new FurgonetkaApiTransportException(
                "cURL error #{$errNo} calling Furgonetka ({$request->describe()}): {$errMsg}"
            );
        }

        return new TransportResponse($httpCode, $body, $responseHeaders);
    }
}
