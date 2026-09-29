# Changelog

All notable changes to `very-code-com/furgonetka-api-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

## [1.0.0]

First release. Compatible with the Furgonetka.pl REST API as of its changelog version 1.0.118 (25 September 2026).
Every behaviour below was checked on the Furgonetka sandbox; what could not be checked is listed under
[Not verified](#not-verified).

### Live-API behaviours handled

These are answers the published specification does not describe, found by running the client against the sandbox
rather than against fixtures. The full list, with reasons, is in
[docs/FURGONETKA-NOTES.md](docs/FURGONETKA-NOTES.md).

- The version of an answer is picked by the media type, and a wrong version is not an error. A number above the
  newest is served as the newest, anything else unknown as v1. Every version lives in one registry with a test per
  operation, and a weekly job compares the registry with the published specification.
- A refresh token is single-use and a successful refresh kills the old access token at once. The token store
  has a lock, the refresh happens under it, a second read follows the lock, and a request that got a 401 is
  repeated once with the current token.
- At most 20 OAuth sessions per application and user. The library never logs in on its own unless you hand it a
  `PasswordReauthorizer`.
- 409 on ordering and on booking a pickup means "regulations not accepted", in a flat body, not "the UUID is
  taken". A repeated command under the same UUID is 400 `commandExists`, which is taken as "already submitted" only
  for a UUID the caller chose. `acceptRegulations()` is only ever an explicit call.
- A carrier's rejection is not an HTTP error. It is the result of the command (`error`, `partial_success`) with
  per-shipment error paths; an unavailable price is `available: false` inside the answer.
- A command aborted while queued ends as `cancelled`, a status the documentation does not list; it is final.
- The documents and CSV links are signed (about ten minutes, another host) and are fetched over https with no
  token.
- A label is a 204 for a package that is only tracked, a 404 for a cancelled shipment. A 410 was never seen.
- Returns go to the address on the label (`sender`), not to the pickup address, so the library warns whenever
  the two differ.
- `PUT` on an address book entry and `POST` of the notification settings replace everything. Requests are built
  from a read (`AddressBookEntry::toRequest()`, `NotificationSettingsRequest::from()`).
- A webhook receiver has to answer HTTP 200 and `{"status":"OK"}`; a webhook can only be saved after a
  successful check of its address, and the log holds only the deliveries that failed.
- Balance dates are days (`YYYY-MM-DD`), and an `items` above 1000 is silently read as 10.
- A phrase the points search cannot place returns the points around a default centre in Warsaw, not an error.
- `POST /packages/validate` with an empty body answers 200 with an old map-shaped error; only 204 means valid.
- A shipment that leaves the EU cannot be ordered through the API alone: the order is a 400 `noSignature` until the
  sender has signed the customs documents on Furgonetka's own page (the shipment's `duty.signature_required` and
  `documents_signed` say where it stands). Norway and Switzerland were ordered, labelled and cancelled after signing.
- A shipment takes the `file_uuid` of a photo uploaded with `type=packagePhoto`, the type the panel sends; the UUID of the
  upload command is refused.
- HS code search needs the user's token although the specification lists none: without it the endpoint answers 500
  (it answered without one in the morning of the day it was checked).
- International DPD is a regulation of its own (`dpd_international`); ordering to Germany is a 409 until it is
  accepted. A country a contract does not serve is a refusal in the validation and the price, not an HTTP error.
- Enums in answers are read leniently: a value the release does not know is `null` and the raw text is kept.

### Added

- Client. `FurgonetkaApiClient` with about 90 methods, configured with `FurgonetkaApiConfig` (`sandbox()`,
  `production()`, `fromEnv()`, `fromArray()`), on a replaceable `TransportInterface` (cURL by default), a PSR-3 logger
  and a `PollingPolicy`. TLS is always verified.
- OAuth2. `OAuthClient` (authorization code with `state`, password with two-factor, refresh, client
  credentials), `TokenManager`, `FileTokenStore`, `InMemoryTokenStore`, `StaticTokenProvider`,
  `PasswordReauthorizer`, and `connectWithPassword()`, `connectWithTwoFactor()`, `connectWithAuthorizationCode()`.
- Shipments. Validation (`validateLocally()`, `warningsFor()`, `validateRemotely()`), prices of several carriers
  at once, create, update, read, list with filters and paging, delete, creation as a command, regulations
  (`pendingRegulations()`, `acceptRegulations()`).
- Ordering and documents. Ordering, labels (PDF, ZPL, EPL; A4 and A6), documents for many shipments, the pickup
  protocol, the confirmation e-mail, courier pickup proposals and booking, cancelling, marking as cancelled,
  aborting a command.
- Tracking. Per parcel, following packages of other systems (also as a command), renaming, simulation on the
  sandbox, pickup point search.
- Returns and readdressing, the "send to me" link, file upload.
- Account. Services, companies, allowed countries, the dictionary, the address book, notification settings, the
  webhook (settings, log, check, resend), the balance, own carrier contracts and announced price list changes.
- Helper commands. CSV export with a download, re-checking shipments in the cart, HS code search.
- `Webhook\WebhookEvent` reads JSON and XML notifications, verifies the checksum and builds the reply.
- Local validation of the rules Furgonetka enforces (errors) and the rules it documents but does not always
  enforce (warnings).
- Secrets stay out of traces, logs and dumps: `#[\SensitiveParameter]` on every parameter that can carry one,
  `redacted()` and `__debugInfo()` on the objects that hold them, request bodies sealed against the trace of a
  closure, and tests that check this with 15-character secrets, because a trace cuts longer text.
- Eighteen examples in [examples/](examples), three of them runnable offline, and a cookbook of 193 recipes in
  [examples/recipes](examples/recipes): shipments by carrier and service, by country (Germany, France, Ireland,
  Norway, Great Britain with customs data) and read-only queries, about a third of them documented refusals. Each was
  run against the sandbox, and `RecipesTest` runs them again with the integration suite.
- `Package::signatureRequired()` and `documentsSigned()` (the customs signature of a shipment that leaves the EU),
  `returnDisposition()` and `readdressingDisposition()` (`ReturnDisposition`, `ReaddressingDisposition`,
  `DispositionStatus`; never seen filled in, because the sandbox refused every return and readdressing).
- Shipments abroad: `Receiver::countryCode`, customs data (`Duty`, `ExportItem`, `DutyType`), local rules for the
  countries that need it, `fetchAllowedCountries()->requiresDuty()`. DPD to Germany and UPS to Norway and Switzerland
  were ordered, labelled and cancelled on the sandbox; see [docs/SHIPPING-ABROAD.md](docs/SHIPPING-ABROAD.md).
- `tools/spec-drift.php` compares the operation registry with the published specification.
- CI. Unit tests on PHP 8.2 to 8.5 and on the lowest dependencies, PHPStan level 8, `composer audit`, a coverage
  floor, a weekly spec drift check, and an integration run against the sandbox (see [docs/TESTING.md](docs/TESTING.md)).

### Not verified

The sandbox could not show these, so the library follows the documentation and its tests say where a body is
built from the specification: a successful return or readdressing (every shipment answers `notPossible`), which
UUID of an uploaded file a shipment takes for customs documents, a carrier's rejection inside an order or pickup result, a 410 on a label,
two-factor login, paging of the webhook log, ordering abroad other than to Germany, Norway and Switzerland, a sale to
Great Britain, and ordering on production (a read-only pass over production was made: public endpoints, login,
account reads, prices and validation).

### Requirements

PHP 8.2 or newer with `ext-curl`, `ext-json`, `ext-libxml`, `ext-mbstring` and `ext-simplexml`, and `psr/log`
2 or 3.

[Unreleased]: https://github.com/very-code-com/furgonetka-api-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/very-code-com/furgonetka-api-php/releases/tag/v1.0.0
