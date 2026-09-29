<?php

declare(strict_types=1);

/**
 * Router script for the loopback server that CurlTransportTest starts (`php -S 127.0.0.1:<port>`).
 *
 * It plays the part of Furgonetka's API in the few ways the transport cares about: it echoes what
 * it received, so a test can see exactly what went over the wire, and it serves the response
 * shapes the real API produces (JSON with a rate-limit header, a PDF, a ZPL text label, an empty
 * 204, a gzip-compressed answer, a redirect, a slow answer).
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/echo') {
    try {
        $echo = json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI'],
            'headers' => getallheaders(),
            // Base64, so that a binary body survives the JSON round trip byte for byte.
            'body_base64' => base64_encode((string) file_get_contents('php://input')),
        ], JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        // A 500 with the reason reads better in a failing test than an empty body would.
        http_response_code(500);
        echo 'The echo could not be encoded: ' . $e->getMessage();

        return true;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo $echo;

    return true;
}

if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    header('Content-Type: application/json');
    header('X-RateLimit-Retry-After: 30');
    echo '{"errors":[{"path":"/receiver/postcode","message":"Invalid postcode","code":"invalid"}]}';

    return true;
}

if ($path === '/oauth/token') {
    // A token endpoint with one property that matters for the refresh race: refresh tokens are
    // single use and rotate. State lives in the file named by FURGONETKA_TEST_OAUTH_STATE
    // ({"refresh": <the one valid refresh token>, "issued": n, "requests": n}) because every
    // request of the built-in server is a fresh script run.
    $stateFile = (string) getenv('FURGONETKA_TEST_OAUTH_STATE');
    parse_str((string) file_get_contents('php://input'), $form);

    if ($stateFile === '' || ($form['grant_type'] ?? '') !== 'refresh_token') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo '{"error":"unsupported_grant_type","error_description":"This test server only knows refresh_token."}';

        return true;
    }

    /** @var array{refresh: string, issued: int, requests: int} $state */
    $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
    $state['requests']++;

    // Long enough for the other workers to reach their lock while this one holds it.
    usleep(200_000);

    header('Content-Type: application/json');

    if (($form['refresh_token'] ?? '') !== $state['refresh']) {
        file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        http_response_code(400);
        echo '{"error":"invalid_grant","error_description":"The refresh token is invalid."}';

        return true;
    }

    $state['issued']++;
    $state['refresh'] = 'refresh-' . $state['issued'];
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));

    echo json_encode([
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'access_token' => 'access-' . $state['issued'],
        'refresh_token' => $state['refresh'],
    ], JSON_THROW_ON_ERROR);

    return true;
}

switch ($path) {
    case '/pdf':
        header('Content-Type: application/pdf');
        // Every byte value, so that nothing in the transport may treat the body as text.
        echo "%PDF-1.4\n" . implode('', array_map('chr', range(0, 255)));
        break;

    case '/zpl':
        header('Content-Type: text/plain; charset=utf-8');
        echo "^XA^FO50,50^FDMarsza\u{142}kowska^FS^XZ";
        break;

    case '/no-content':
        http_response_code(204);
        break;

    case '/multi':
        header('X-Multi: first', false);
        header('X-Multi: second', false);
        header('X-Single: one');
        echo '{}';
        break;

    case '/gzip':
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        echo gzencode('{"compressed":true}');
        break;

    case '/redirect':
        header('Location: /echo', true, 302);
        break;

    case '/slow':
        sleep(2);
        echo '{}';
        break;

    default:
        http_response_code(404);
        echo 'no such route';
}

return true;
