# furgonetka-api-php

**PHP client library for the Furgonetka.pl REST API.**

Compare prices of DPD, InPost, DHL, UPS, GLS, FedEx, Poczta Polska, ORLEN Paczka and others behind one API of the
courier broker [Furgonetka.pl](https://furgonetka.pl): create and order shipments, download labels, book pickups, track
parcels, find pickup points, send abroad and receive tracking webhooks. No framework and no HTTP library to install:
plain cURL, PSR-3 for logging.

[![Latest Version](https://img.shields.io/packagist/v/very-code-com/furgonetka-api-php.svg)](https://packagist.org/packages/very-code-com/furgonetka-api-php)
[![Total Downloads](https://img.shields.io/packagist/dt/very-code-com/furgonetka-api-php.svg)](https://packagist.org/packages/very-code-com/furgonetka-api-php)
[![CI](https://github.com/very-code-com/furgonetka-api-php/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/very-code-com/furgonetka-api-php/actions/workflows/ci.yml)
[![Integration](https://github.com/very-code-com/furgonetka-api-php/actions/workflows/integration.yml/badge.svg?branch=master)](https://github.com/very-code-com/furgonetka-api-php/actions/workflows/integration.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://phpstan.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue)](https://www.php.net)
[![License](https://img.shields.io/packagist/l/very-code-com/furgonetka-api-php.svg)](LICENSE)

## Installation

```bash
composer require very-code-com/furgonetka-api-php
```

Requires PHP 8.2+ with `ext-curl`, `ext-json`, `ext-libxml`, `ext-mbstring` and `ext-simplexml`, a Furgonetka.pl account
and an OAuth application. [Getting started](docs/GETTING-STARTED.md) shows how to get the keys, for the sandbox and for
production.

## Quick start

Keep the keys in the environment (`FURGONETKA_CLIENT_ID`, `FURGONETKA_CLIENT_SECRET`, `FURGONETKA_ENV`) and log in once
from a terminal (`php examples/02_password_grant_and_2fa.php`); the token is stored and refreshes itself after that.

```php
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Dto\{Parcel, PriceScope, Pickup, Receiver, ShipmentRequest};
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\{FurgonetkaApiClient, FurgonetkaApiConfig};

$client = FurgonetkaApiClient::create(
    FurgonetkaApiConfig::fromEnv(),
    new FileTokenStore('/var/lib/shop/furgonetka-token.json'),
);

$service = $client->listServices()->first(Carrier::Dpd)           // your contract with the carrier
    ?? throw new RuntimeException('The account has no DPD contract.');

$request = new ShipmentRequest(
    serviceId: $service->id ?? throw new RuntimeException('The service has no id.'),
    pickup: new Pickup('Marszalkowska 100', '00-026', 'Warszawa', name: 'Jan Kowalski', countryCode: 'PL', email: 'jan@example.com', phone: '500600700'),
    receiver: new Receiver(name: 'Anna Nowak', street: 'Florianska 15', postcode: '31-021', city: 'Krakow', countryCode: 'PL', email: 'anna@example.com', phone: '600700800'),
    parcels: [Parcel::package(30, 40, 20, 5.0, 100.0, 'Books')],    // cm, kg, value in PLN
);

// Prices of several carriers at once; a carrier that cannot take the parcel is `available: false`.
$quotes = $client->calculatePrices($request, PriceScope::carriers(Carrier::Dpd, Carrier::InPost, Carrier::Gls));

// Create the shipment, then order it. A carrier's rejection is a result, not an exception.
$package = $client->createPackage($request);
$result  = $client->orderPackages([$package]);

if ($result->isSuccessful()) {
    file_put_contents('label.pdf', $client->fetchLabel($package)->bytes);
    echo implode(', ', $client->fetchPackage($package)->waybillNumbers());
} else {
    foreach ($result->errorsFor((string) $package->id) as $error) {
        echo $error->path, ': ', $error->message, PHP_EOL;
    }
}
```

Ordering on production charges the account and books a carrier. Try everything on the sandbox first; the
[examples](examples) are dry unless you ask them to order.

## Documentation

| Document | Contents |
|----------|----------|
| [Getting started](docs/GETTING-STARTED.md) | Accounts and OAuth keys for the sandbox and production, configuration, debug mode, what to know before the first order |
| [Authentication and tokens](docs/AUTHENTICATION.md) | The grants, token stores, several accounts, Laravel and Symfony |
| [Sending abroad](docs/SHIPPING-ABROAD.md) | Customs data, contracts, regulations, the customs signature outside the EU |
| [API reference](docs/API.md) | Every client method, the objects they take and return, local validation, exceptions, the webhook parser |
| [Furgonetka's quirks](docs/FURGONETKA-NOTES.md) | What the API does that its documentation does not say, and what was not verified |
| [Testing](docs/TESTING.md) | Testing your code against a fake transport, running this library's tests, CI |
| [Examples](examples) | Eighteen runnable scripts, three of them offline |
| [Cookbook](examples/recipes) | About two hundred small recipes by carrier, service and country, each run against the sandbox |
| [Changelog](CHANGELOG.md) | Releases |

## License

[Apache License 2.0](LICENSE), see [NOTICE](NOTICE) for attribution requirements. You may use, distribute and modify
this library freely, and must retain the `NOTICE` file and copyright notices in any redistribution or derivative work.

*Built by [Very Code](https://very-code.com). Contributions welcome, open an issue or PR.*
