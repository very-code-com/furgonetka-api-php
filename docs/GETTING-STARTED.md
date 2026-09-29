# Getting started

From nothing to a first call: the accounts and keys you need, how to give them to the library, and what to know
before the first order. The sandbox and production are separate systems, so do this once for each.

## Getting access

You need an **account**, an **OAuth application** (its Client ID and Client Secret) and an account that is **allowed
to order**, once per environment. The sandbox and production are separate systems with separate accounts,
applications and keys: nothing you create in one exists in the other. Start on the sandbox.

### Sandbox

1. **Account.** Register at [sandbox.furgonetka.pl/wejdz/rejestracja](https://sandbox.furgonetka.pl/wejdz/rejestracja)
   (the page title says "[Test]"). A private account is enough, no company data. Sign up with an e-mail and a
   password, **not** through Google, Apple, Allegro, Microsoft, WP or Onet: the `password` grant needs both. Use an
   address you can read, because the sandbox may send real e-mails (activation, shipping documents). Tick only the
   mandatory consent (terms and privacy policy) and confirm the e-mail if a message arrives. The form has a
   reCAPTCHA, so this step is manual.
2. **Leave two-factor authentication off** on an account that scripts or CI log in to: a `password` login then
   answers `2fa_required`, which the library can finish (`connectWithTwoFactor()`) but a scheduled job cannot.
3. **OAuth application.** Logged in, open [sandbox.furgonetka.pl/api/aplikacje-oauth](https://sandbox.furgonetka.pl/api/aplikacje-oauth)
   and choose "Zarejestruj nowa aplikacje" (register a new application). Fill in:
   - Nazwa (name), required: anything that tells your applications apart, for instance `my-shop sandbox`.
   - Redirect URI: Furgonetka's help calls it optional, but the authorization code flow needs it and it has to
     match what you later send, character for character. For a local try-out
     `http://localhost:8080/oauth/callback` is accepted (plain `http`); for a real application use the `https`
     address of your callback. Leave it out only if you will never use the authorization code flow.

   Confirm, and the next screen shows the **Client ID** and the **Client Secret**. Treat the secret like a
   password.
4. **A verified phone number** in the account settings. Without it ordering is refused ("Please verify Your phone
   number"); everything before ordering works.
5. **Regulations.** Before the first order with a carrier its regulations have to be accepted, or the order is a 409.
   The library never accepts them for you: `pendingRegulations($packageIds)` lists what a shipment needs and
   `acceptRegulations()` accepts, explicitly. International DPD has a regulation of its own
   (`dpd_international`), separate from the domestic one. See [example 04](../examples/04_create_and_order.php).

Check the keys without spending a login session (`client_credentials` needs no user):

```bash
curl -s -u "$FURGONETKA_CLIENT_ID:$FURGONETKA_CLIENT_SECRET" \
  --data-urlencode grant_type=client_credentials --data-urlencode scope=api \
  https://api.sandbox.furgonetka.pl/oauth/token | cut -c1-60
```

`{"token_type":"Bearer","expires_in":3600,...` means the keys are right; `{"error":"invalid_client",...` means a
wrong Client ID or Secret, or an application made on the other environment. That token cannot reach your
shipments; the library uses the `password` grant or the authorization code flow for that (see below).

### Production

The steps are the same, on [furgonetka.pl](https://furgonetka.pl) instead: register an account, open
`https://furgonetka.pl/api/aplikacje-oauth` and register an application (Furgonetka's own instructions:
[furgonetka.pl/pomoc/279](https://furgonetka.pl/pomoc/279,jak_uzyskac_client_id_oraz_client_secret)). Production keys
are new keys; the sandbox ones do not work there. Differences that matter:

- Every order is real. It books a carrier and is charged to the account, and nothing in this library makes a
  production order cheaper or reversible.
- The redirect URI is the `https` address of your application, if you use the authorization code flow.
- An account with several companies takes the company as `X-Company-Id`: `listCompanies()` shows the ids, then
  `FURGONETKA_COMPANY_ID` or `$client->withCompany($id)`.
- `listServices()` shows the services of this account, Furgonetka's own contracts and any of yours. A recipe or an
  example that names a carrier the account has no service for is skipped or refused.
- The phone number and the regulations are as above, on the production account.
- Start with reads: `listServices()`, `fetchBalance()`, `validateRemotely()` and `calculatePrices()`. Order last, and
  one shipment. `FURGONETKA_ENV=production` is the default, so a missing variable means production, not the
  sandbox: set it explicitly everywhere.

This library was developed and tested on the sandbox. Its test suite has never run against production. A read-only
smoke test did, on 2026-09-29: the public endpoints, the keys (`client_credentials`), one password login, the services,
companies, balance and shipment list of an account, and the price and remote validation of a sample domestic shipment
for DPD, InPost and GLS. Everything answered as on the sandbox. Ordering, labels, pickups and every other call that
spends money or books a carrier were not tried there (see [Not verified](FURGONETKA-NOTES.md#not-verified)), so treat
the first order as your own test.

### Putting the keys to work

Keep the keys in the environment or in your framework's secret store, never in the repository, and never in a
public client. For a shell or a `.env` file that your application (not this library) loads:

```bash
FURGONETKA_ENV=sandbox                    # or production
FURGONETKA_CLIENT_ID=...
FURGONETKA_CLIENT_SECRET=...
FURGONETKA_REDIRECT_URI=http://localhost:8080/oauth/callback     # only for the authorization code flow
FURGONETKA_LANGUAGE=en_GB                 # pl_PL for Furgonetka's messages in Polish
```

Then log in **once**, from a terminal or an admin screen, with the login and password of the Furgonetka account
(`connectWithPassword()`, or `php examples/02_password_grant_and_2fa.php`). The token lands in your token store and
refreshes itself from then on. An application that connects other people's accounts sends each of them through the
authorization code flow instead ([example 01](../examples/01_authorize_user.php)). Details and the rules about
sessions are under [Authentication and tokens](AUTHENTICATION.md).

| You see | It means |
|---------|----------|
| `invalid_client` (401) | wrong Client ID or Secret, or the application belongs to the other environment |
| `invalid_grant` (400) | wrong login or password on a login; on a refresh, a token that was already used |
| `2fa_required` | the account has two-factor authentication: `FurgonetkaApiTwoFactorRequiredException`, then `connectWithTwoFactor()` |
| `FurgonetkaApiReauthorizationRequiredException` | the token is dead (revoked, or lost a refresh race): log in again |
| 409 on an order or a pickup | regulations not accepted, see `pendingRegulations()` |
| "Please verify Your phone number" | the account's phone number is not verified |
| `FurgonetkaApiRateLimitException` (429) | the hourly limit of that endpoint, 500 or 5000; `retryAfterSeconds()` says when |

## Configuration

```php
// Named constructors
$config = FurgonetkaApiConfig::sandbox($clientId, $clientSecret, $redirectUri);
$config = FurgonetkaApiConfig::production($clientId, $clientSecret);

// From environment variables (recommended)
$config = FurgonetkaApiConfig::fromEnv();

// From an array (framework config)
$config = FurgonetkaApiConfig::fromArray([
    'client_id' => '...', 'client_secret' => '...', 'env' => 'sandbox', 'language' => 'pl_PL',
]);
```

The library never reads `.env` files and does not depend on a dotenv library: loading variables is the
application's job (Laravel, Symfony Dotenv, Docker, systemd, CI secrets). `fromEnv()` reads `getenv()`, `$_ENV`
and `$_SERVER`. A value that is present and wrong (a typo in `FURGONETKA_ENV`, a non-number timeout) is refused
instead of quietly falling back, because a misspelt `sandbox` would send a shop to production.

| Env variable | Required | Default | Description |
|--------------|----------|---------|-------------|
| `FURGONETKA_CLIENT_ID` | yes* | - | Client ID of the OAuth application (separate for sandbox and production) |
| `FURGONETKA_CLIENT_SECRET` | yes* | - | Its client secret |
| `FURGONETKA_ENV` | no | `production` | `sandbox` or `production` |
| `FURGONETKA_REDIRECT_URI` | no | - | Only for the authorization code flow; must equal the registered address |
| `FURGONETKA_LANGUAGE` | no | `en_GB` | `pl_PL` for Furgonetka's messages in Polish |
| `FURGONETKA_COMPANY_ID` | no | - | `X-Company-Id`, for accounts with several companies |
| `FURGONETKA_TIMEOUT`, `FURGONETKA_CONNECT_TIMEOUT` | no | `30`, `10` | Seconds |
| `FURGONETKA_DEBUG` | no | `0` | `1` attaches the raw answer to exceptions (see [Debug mode](#debug-mode)) |

\* A client for the public endpoints only (`findPoints()`, `fetchDictionary()`, `fetchAllowedCountries()`) needs no keys: `FurgonetkaApiConfig::production()` with no arguments.

`FURGONETKA_USERNAME` and `FURGONETKA_PASSWORD` are read only by `PasswordReauthorizer::fromEnv()`, if you choose
to use it (see below); the configuration does not know them.

**Environments:**

| Environment | API | Panel and documentation |
|-------------|-----|-------------------------|
| Production | `https://api.furgonetka.pl` | `https://furgonetka.pl/api/rest` |
| Sandbox | `https://api.sandbox.furgonetka.pl` | `https://sandbox.furgonetka.pl/api/rest` |

The sandbox is a separate environment with test data: a new account starts with about 35 ordered shipments, and the
request that simulates tracking exists only there. On production every order is real and billable.

### Debug mode

Set `debug` (`FURGONETKA_DEBUG=1`, or `'debug' => true` in `fromArray()`) to make every exception carry the **raw
Furgonetka answer**, with tokens masked, and to log a debug report through your PSR-3 logger:

```php
try {
    $client->orderPackages([$package]);
} catch (\VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException $e) {
    echo $e->getRawResponse();   // what Furgonetka answered, or null
    echo $e->getDebugReport();   // class, message, the raw answer and the stack trace
}
```

Leave it off in production. Either way secrets never appear in a message, a log or a trace.

## Things worth knowing before the first order

**1. A carrier's rejection is not an exception.** Ordering, booking a pickup, cancelling, creating and the other
commands answer 200 and put the outcome in the result: `isSuccessful()`, `isPartial()`, `hasFailed()`,
`errorsFor($packageId)`. A price that cannot be given is `available: false` next to the others. Only refusals of
the request itself (a wrong field, a bad token, a missing regulation) are exceptions, and each HTTP status has its
own class.

**2. Commands are safe to repeat if you choose the UUID.** `orderPackages([...], uuid: $uuid)` after a network error
reads the result of the first attempt instead of ordering twice. The exception of a network failure carries the UUID
(`commandUuid()`). Without a UUID of your own the client makes one and cannot know it is a retry.

**3. Furgonetka chooses the API version quietly.** A wrong version in `Accept` is not an error, it returns another
shape of the same data. The library keeps every version in one registry, sends it in both headers, and a weekly
job compares that registry with the specification Furgonetka publishes.

**4. Returns go to the address on the label.** `pickup` is where the courier collects; `LabelSender` is what is
printed ("other data on the label"), and a return goes there. `warningsFor()` says so when the two differ.

**5. The address book and the notification settings are replaced whole.** Change one field of an entry with
`$entry->toRequest()->with(['city' => 'Krakow'])`, and build notification settings from a read
(`NotificationSettingsRequest::from(...)`); leaving something out empties it.

**6. A webhook receiver has to answer `{"status":"OK"}`** with HTTP 200. `WebhookEvent::acknowledgement()` builds
it in the format the notification came in. Read [FURGONETKA-NOTES.md](FURGONETKA-NOTES.md) for these and the
other quirks (there are many) before you assume the specification is the whole truth.

**7. Returns and readdressing are written from the specification.** The sandbox refuses both for every shipment it
was tried on, so their successful answers have not been seen. The library's tests say where a body is built from
the specification and not captured.

