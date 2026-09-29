<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\PasswordReauthorizer;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\TokenManager;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Transport\CurlTransport;
use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;

/**
 * Base of the tests that talk to the real Furgonetka sandbox (api.sandbox.furgonetka.pl).
 *
 * They run only when `FURGONETKA_SANDBOX=1` and the application and account credentials are in
 * the environment, so a plain `phpunit` run and every fork's CI stay offline. Locally the values
 * come from `.env.sandbox`: `set -a; source .env.sandbox; set +a`.
 *
 * Every password grant opens one of the 20 OAuth sessions the sandbox allows per user and
 * application, so a test must not log in more than it needs to. The tests that only need a
 * logged-in client share one token that stays in a file between runs ({@see self::sharedClient()}):
 * a run logs in with the password only when that file is missing or its session is gone. Only
 * `OAuthTest` still spends a session per run, because it tests the grant itself.
 */
abstract class SandboxTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('FURGONETKA_SANDBOX') !== '1') {
            self::markTestSkipped('Set FURGONETKA_SANDBOX=1 (and the FURGONETKA_* credentials) to run the sandbox tests.');
        }

        foreach (['FURGONETKA_CLIENT_ID', 'FURGONETKA_CLIENT_SECRET', 'FURGONETKA_USERNAME', 'FURGONETKA_PASSWORD'] as $name) {
            if ($this->env($name) === '') {
                self::markTestSkipped("{$name} is not set.");
            }
        }
    }

    protected function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    /** The sandbox config for the application keys in the environment. */
    protected function config(bool $debug = true): FurgonetkaApiConfig
    {
        $redirectUri = $this->env('FURGONETKA_REDIRECT_URI');
        $base = FurgonetkaApiConfig::sandbox($this->env('FURGONETKA_CLIENT_ID'), $this->env('FURGONETKA_CLIENT_SECRET'), $redirectUri !== '' ? $redirectUri : null);

        return new FurgonetkaApiConfig(credentials: $base->credentials, sandbox: true, debug: $debug);
    }

    protected function transport(): TransportInterface
    {
        return new CurlTransport();
    }

    /**
     * The token every sandbox test shares, kept between runs in the system temporary directory.
     *
     * The directory name comes from a hash of the application and the account, so another
     * application or account never reads this token (the token also carries the environment and a
     * hash of the client id, and a foreign one is ignored). The file is 0600 in a 0700 directory.
     * Delete the directory to force a new login. `FURGONETKA_TOKEN_FILE` names another file, which is how CI
     * keeps the token between runs.
     */
    protected function sharedTokenStore(): FileTokenStore
    {
        $file = $this->env('FURGONETKA_TOKEN_FILE');

        if ($file !== '') {
            return new FileTokenStore($file);
        }

        $key = substr(hash('sha256', $this->env('FURGONETKA_CLIENT_ID') . "\0" . $this->env('FURGONETKA_USERNAME')), 0, 16);

        return new FileTokenStore(sys_get_temp_dir() . '/furgonetka-api-php-sandbox-' . $key . '/token.json');
    }

    /** Logs in with the password when the store has no usable token: one session, then none for about 30 days. */
    private function passwordReauthorizer(): PasswordReauthorizer
    {
        return new PasswordReauthorizer($this->env('FURGONETKA_USERNAME'), $this->env('FURGONETKA_PASSWORD'));
    }

    /** A client on the shared token, for tests that call the API through the facade. */
    protected function sharedClient(?LoggerInterface $logger = null): FurgonetkaApiClient
    {
        return FurgonetkaApiClient::create($this->config(), $this->sharedTokenStore(), $logger, $this->transport(), $this->passwordReauthorizer());
    }

    /**
     * The shipments that came with the account: ordered, with a waybill number and no reference of
     * our own (every shipment the tests make has one). Newest first. Tests that need an ordered
     * shipment use these instead of making and ordering their own, which leaves waiting copies
     * behind and cannot be undone.
     *
     * @return list<Package>
     */
    protected function starterShipmentsOf(FurgonetkaApiClient $client): array
    {
        $page = $client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Ordered));

        return array_values(array_filter(
            $page->packages,
            static fn (Package $p): bool => $p->userReferenceNumber === null && $p->waybillNumbers() !== [],
        ));
    }

    /** The current shared access token, for tests that build requests by hand. */
    protected function sharedAccessToken(): string
    {
        return (new TokenManager(new OAuthClient($this->config(), $this->transport()), $this->sharedTokenStore(), $this->passwordReauthorizer()))->accessToken();
    }
}
