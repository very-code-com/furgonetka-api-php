# Testing

How to test code that uses the library, and how the library tests itself.

## Injecting a transport

```php
new FurgonetkaApiClient(
    config:    FurgonetkaApiConfig,
    transport: TransportInterface,                 // send(TransportRequest): TransportResponse
    tokens:    ?TokenProviderInterface = null,
    logger:    ?Psr\Log\LoggerInterface = null,
    // ... oauth, sleeper, polling, clock
)
```

`FurgonetkaApiClient::create()` builds the defaults (cURL, a `TokenManager` around your store). Implement
`TransportInterface` to use a PSR-18 client or, in tests, a scripted fake; see
[examples/09_di_and_testing.php](../examples/09_di_and_testing.php) and the client tests in `tests/Unit` for the
pattern this library uses on itself (no network). `TransportRequest::redacted()` masks the `Authorization` header and
the secrets in the body, so a request can be logged.

## Running the tests

```bash
composer install

# Unit tests (no network; two classes start `php -S` on the loopback interface and skip themselves if they cannot)
vendor/bin/phpunit --testsuite unit

# Integration tests against the real sandbox
set -a; source .env.sandbox; set +a       # FURGONETKA_SANDBOX=1 and the four credentials below
vendor/bin/phpunit --testsuite integration

# Static analysis (PHPStan level 8, no baseline, no ignores)
vendor/bin/phpstan analyse --memory-limit=1G
```

The integration suite needs `FURGONETKA_SANDBOX=1`, `FURGONETKA_CLIENT_ID`, `FURGONETKA_CLIENT_SECRET`,
`FURGONETKA_USERNAME` and `FURGONETKA_PASSWORD` of a **sandbox** application and account (without two-factor
authentication), and optionally `FURGONETKA_REDIRECT_URI`. It creates, orders and cancels shipments on the sandbox
(DPD on Furgonetka's contract) and cleans up after itself; add `FURGONETKA_ALLOW_ORDERS=0` to keep a run read-only.
**Never point it at production keys.**

The tests share one token, kept in a file (`FURGONETKA_TOKEN_FILE`, by default one file in the temporary
directory), so a run logs in at most once. The single test that opens a password session of its own is in the group
`oauth-grant`; leave it out with `--exclude-group oauth-grant`.

### Continuous integration

| Workflow | Job | What it does |
|----------|-----|--------------|
| [`ci.yml`](../.github/workflows/ci.yml) | `test` | Unit tests on PHP 8.2 to 8.5, and a `--prefer-lowest` leg on 8.2 that checks the declared dependency floor; lints every example and runs the offline ones |
| | `analyse` | PHPStan level 8, `composer validate --strict --check-lock`, and a guard that no `.env` file is tracked |
| | `security` | `composer audit --locked` |
| | `coverage` | Unit line coverage, failing under an 85% floor |
| [`spec-drift.yml`](../.github/workflows/spec-drift.yml) | `drift` | Weekly: fetches Furgonetka's published specification and compares its operations with the registry |
| [`integration.yml`](../.github/workflows/integration.yml) | `integration` | The sandbox suite, weekly, on pushes to `master` that touch code it exercises, and on demand |

`ci.yml` needs no secrets. The integration workflow is kept out of the pull-request gate (forks cannot read
secrets, and it creates sandbox shipments), and it is careful with logins, because Furgonetka allows 20 sessions
per application and user and revokes the oldest at the 21st: the token is kept between runs with `actions/cache`
(runs are serialised, so a rotated refresh token is always the newest), the test that logs in by itself runs only
in the weekly run and when asked for, and a push that changes nothing the suite exercises does not run it. To enable
it add `FURGONETKA_CLIENT_ID`, `FURGONETKA_CLIENT_SECRET`, `FURGONETKA_USERNAME` and `FURGONETKA_PASSWORD` to a
repository **environment** named `furgonetka-sandbox`, restricted to `master`, and use an OAuth application (and
ideally an account) that exists for CI alone, so its sessions do not count against the one you develop with.
Without the secrets the job reports "not configured" and stops instead of failing.
