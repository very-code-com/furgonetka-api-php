<?php

declare(strict_types=1);

/**
 * Shared by the examples: the autoloader, a client built from the environment, and a few helpers.
 *
 * It is not part of the library. A real application wires the client into its own container; see
 * 09_di_and_testing.php for that.
 *
 * The examples read their settings from the environment (the library never reads .env files):
 *
 *   FURGONETKA_CLIENT_ID, FURGONETKA_CLIENT_SECRET   the OAuth application from the Furgonetka panel
 *   FURGONETKA_ENV                                   sandbox or production (default production)
 *   FURGONETKA_REDIRECT_URI                          only for 01_authorize_user.php
 *   FURGONETKA_TOKEN_FILE                            where the token is kept (default: the temporary
 *                                                    directory, one file per environment)
 *
 * Log in once with 02_password_grant_and_2fa.php (or 01_authorize_user.php); after that the client
 * refreshes the token by itself.
 */

use Psr\Log\LoggerInterface;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Pickup;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;

require __DIR__ . '/../vendor/autoload.php';

/** The configuration from the environment. Throws when the application keys are missing. */
function exampleConfig(): FurgonetkaApiConfig
{
    return FurgonetkaApiConfig::fromEnv();
}

/** The file the examples keep the token in: FURGONETKA_TOKEN_FILE, or one file per environment in the temporary directory. */
function exampleTokenFile(FurgonetkaApiConfig $config): string
{
    $file = getenv('FURGONETKA_TOKEN_FILE');

    if (is_string($file) && $file !== '') {
        return $file;
    }

    return sys_get_temp_dir() . '/furgonetka-api-php-examples-' . $config->getEnvironment() . '/token.json';
}

/** A client that keeps its token in that file and refreshes it when it is about to expire. */
function exampleClient(?LoggerInterface $logger = null): FurgonetkaApiClient
{
    $config = exampleConfig();

    return FurgonetkaApiClient::create($config, new FileTokenStore(exampleTokenFile($config)), $logger);
}

/**
 * A client for the public endpoints (points, the dictionary, allowed countries): no token is needed, and the
 * application keys are not either. It uses the sandbox when FURGONETKA_ENV says so.
 */
function examplePublicClient(): FurgonetkaApiClient
{
    $sandbox = getenv('FURGONETKA_ENV') === 'sandbox';

    return FurgonetkaApiClient::create($sandbox ? FurgonetkaApiConfig::sandbox() : FurgonetkaApiConfig::production());
}

/** True when the flag was given on the command line, for instance `--order`. */
function exampleFlag(string $name): bool
{
    /** @var list<string> $argv */
    $argv = $_SERVER['argv'] ?? [];

    return in_array($name, array_slice($argv, 1), true);
}

/** The n-th argument that is not a flag (1 is the first), or null. */
function exampleArgument(int $position): ?string
{
    /** @var list<string> $argv */
    $argv = $_SERVER['argv'] ?? [];
    $arguments = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));

    return $arguments[$position - 1] ?? null;
}

function exampleHeading(string $text): void
{
    echo PHP_EOL . $text . PHP_EOL . str_repeat('-', strlen($text)) . PHP_EOL;
}

/**
 * Runs the body of an example and turns what usually goes wrong into a message that says what to do,
 * instead of a stack trace. The exit code is 1 on failure.
 *
 * @param callable(): void $main
 */
function exampleRun(callable $main): never
{
    try {
        $main();
    } catch (FurgonetkaApiReauthorizationRequiredException $e) {
        fwrite(STDERR, "There is no usable token yet. Log in first:\n  php examples/02_password_grant_and_2fa.php\n(or run 01_authorize_user.php in a browser).\n");
        exit(1);
    } catch (FurgonetkaApiBadRequestException $e) {
        fwrite(STDERR, 'Furgonetka refused the request: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    } catch (FurgonetkaApiException $e) {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
        exit(1);
    } catch (\InvalidArgumentException $e) {
        // The configuration: FurgonetkaApiConfig::fromEnv() refuses missing keys and bad values.
        fwrite(STDERR, $e->getMessage() . PHP_EOL . "Set FURGONETKA_CLIENT_ID, FURGONETKA_CLIENT_SECRET and FURGONETKA_ENV (sandbox or production).\n");
        exit(1);
    } catch (\RuntimeException $e) {
        // A refusal of the example itself (a missing argument, a guard), not of the library.
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    exit(0);
}

/**
 * A shipment that Furgonetka accepts, on the account's contract with a carrier: a 5 kg parcel from Warsaw
 * to Krakow. Replace the addresses with real ones before ordering anything on production.
 */
function exampleShipment(FurgonetkaApiClient $client, Carrier $carrier = Carrier::Dpd, ?string $reference = null): ShipmentRequest
{
    $service = $client->listServices()->first($carrier);

    if ($service === null || $service->id === null) {
        throw new \RuntimeException("The account has no contract with {$carrier->value}.");
    }

    return new ShipmentRequest(
        serviceId: $service->id,
        pickup: new Pickup('Marszalkowska 100', '00-026', 'Warszawa', name: 'Jan Kowalski', countryCode: 'PL', email: 'jan@example.com', phone: '500600700'),
        receiver: new Receiver(name: 'Anna Nowak', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'anna@example.com', phone: '600700800'),
        parcels: [Parcel::package(30, 40, 20, 5.0, 100.0, 'Books')],
        userReferenceNumber: $reference,
    );
}

/** The newest shipment of the account that has been ordered, or null. */
function exampleLatestOrdered(FurgonetkaApiClient $client): ?Package
{
    foreach ($client->listPackages(new PackageListQuery(limit: 20, listType: ListType::Ordered)) as $package) {
        if ($package->isOrdered() && $package->waybillNumbers() !== []) {
            return $package;
        }
    }

    return null;
}
