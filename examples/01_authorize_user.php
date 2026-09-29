<?php

declare(strict_types=1);

/**
 * The authorization code flow: an application that connects other people's Furgonetka accounts (a SaaS
 * product, a plugin, an agency tool) sends the user to Furgonetka, and gets a code back.
 *
 *   FURGONETKA_CLIENT_ID=... FURGONETKA_CLIENT_SECRET=... FURGONETKA_ENV=sandbox \
 *   FURGONETKA_REDIRECT_URI=http://localhost:8080/oauth/callback \
 *   php -S localhost:8080 examples/01_authorize_user.php
 *
 * then open http://localhost:8080/ and press the button. The redirect URI has to be exactly the one
 * registered for the OAuth application in the Furgonetka panel.
 *
 * What it shows:
 *   - authorizationUrl() gives the URL and a `state`, which belongs in the user's session;
 *   - connectWithAuthorizationCode() checks the state before anything goes to the network, exchanges the
 *     code (it lives 30 seconds) and keeps the tokens in the store;
 *   - the store here is a file. An application with many accounts keeps one store per account, for
 *     instance a TokenStoreInterface over its database with the account as the key.
 *
 * A shop on its own Furgonetka account does not need this: see 02_password_grant_and_2fa.php.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Run this one as a web server:\n  php -S localhost:8080 examples/01_authorize_user.php\nand open http://localhost:8080/ (see the comment at the top).\n");
    exit(1);
}

function page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head><body>'
        . '<h1>' . htmlspecialchars($title) . '</h1>' . $body . '</body></html>';
}

session_start();
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

try {
    $client = exampleClient();

    if ($path === '/') {
        page('Connect to Furgonetka', '<p><a href="/connect">Connect with Furgonetka.pl</a></p>');
    } elseif ($path === '/connect') {
        $request = $client->oauth()->authorizationUrl();
        $_SESSION['furgonetka_state'] = $request->state;

        header('Location: ' . $request->url);
    } elseif ($path === '/oauth/callback') {
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        $expected = $_SESSION['furgonetka_state'] ?? '';

        if (!is_string($code) || !is_string($state) || !is_string($expected)) {
            http_response_code(400);
            page('Not connected', '<p>The callback has no code or no state: ' . htmlspecialchars((string) ($_GET['error_description'] ?? $_GET['error'] ?? 'unknown reason')) . '</p>');
        } else {
            $client->connectWithAuthorizationCode($code, $state, $expected);
            unset($_SESSION['furgonetka_state']);

            $services = $client->listServices();
            page('Connected', '<p>The tokens are stored in ' . htmlspecialchars(exampleTokenFile(exampleConfig())) . '.</p>'
                . '<p>The account has ' . count($services) . ' carrier contracts.</p>');
        }
    } else {
        http_response_code(404);
        page('Not found', '<p>Nothing here.</p>');
    }
} catch (FurgonetkaApiException | \InvalidArgumentException $e) {
    http_response_code(500);
    page('Something went wrong', '<p>' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage()) . '</p>');
}
