<?php

declare(strict_types=1);

/**
 * One contender in the token refresh race (see TokenRaceTest). Run as a separate PHP process:
 *
 *   php refresh_worker.php <token file> <server base url> <start at, unix time with fraction> <mode>
 *
 * It builds the real stack (cURL, FileTokenStore, TokenManager) against the loopback token
 * server, waits for the start time so that all workers hit the lock together, and prints the
 * access token it ended up with. Mode `expired` just asks for a token; mode `refused` asks once
 * first, then, at the start time, reports that token as refused (a 401) and asks again.
 */

use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\TokenManager;
use VeryCodeCom\FurgonetkaApi\Tests\Support\LoopbackTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;
use VeryCodeCom\FurgonetkaApi\Transport\CurlTransport;

require __DIR__ . '/../../vendor/autoload.php';

[, $tokenFile, $baseUrl, $startAt, $mode] = $argv + [null, '', '', '0', 'expired'];

$manager = new TokenManager(
    new OAuthClient(TokenFactory::config(), new LoopbackTransport(new CurlTransport(), (string) $baseUrl)),
    new FileTokenStore((string) $tokenFile),
);

try {
    if ($mode === 'refused') {
        $manager->accessToken();
    }

    while (microtime(true) < (float) $startAt) {
        usleep(1_000);
    }

    if ($mode === 'refused') {
        $manager->invalidate();
    }

    echo $manager->accessToken();
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}
