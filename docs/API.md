# API reference

Every public method of `FurgonetkaApiClient`, the objects they take and return, the local validation rules, the
exceptions and the webhook parser. Quirks of Furgonetka's own behaviour are in
[FURGONETKA-NOTES.md](FURGONETKA-NOTES.md); the runnable versions of most of this are in
[../examples/](../examples).

Namespace: `VeryCodeCom\FurgonetkaApi`. Requests and responses use the newest version of each endpoint.

## What it covers

Every operation of Furgonetka's REST API that a shop or a shipping tool needs, on the newest version of each
endpoint:

| Area | Methods |
|------|---------|
| Login | `connectWithPassword()`, `connectWithTwoFactor()`, `connectWithAuthorizationCode()`, `oauth()` |
| Account | `listServices()`, `listCompanies()`, `fetchAllowedCountries()`, `fetchDictionary()`, `withCompany()` |
| Regulations | `listRegulations()`, `pendingRegulations()`, `acceptRegulations()` |
| Shipments | `validateLocally()`, `warningsFor()`, `validateRemotely()`, `calculatePrices()`, `createPackage()`, `updatePackage()`, `fetchPackage()`, `listPackages()`, `deletePackage()`, `createPackageAsync()` |
| Ordering | `orderPackages()`, `cancelPackages()`, `markAsCancelled()`, `sendDocumentsEmail()`, `revalidatePackages()` |
| Documents | `fetchLabel()` (PDF, ZPL, EPL; A4, A6), `requestDocuments()` and `downloadDocuments()`, `fetchProtocol()` |
| Courier | `fetchPickupProposals()`, `orderPickup()` |
| Tracking | `fetchTracking()`, `addToTracking()`, `addToTrackingAsync()`, `renameTrackedPackage()`, `simulateTracking()` (sandbox) |
| Points | `findPoints()` |
| Returns | `calculateReturnPrice()`, `returnPackage()`, `calculateReaddressingPrice()`, `readdressPackage()`, `createSendToMeLink()` |
| Files | `uploadFile()`, `fetchFileUploads()` |
| Address book | `listAddressBook()`, `createAddressBookEntry()`, `replaceAddressBookEntry()`, `deleteAddressBookEntries()`, `validateAddressBookEntry()` |
| Reports | `exportPackagesCsv()` and `downloadCsvExport()`, `searchHsCodes()`, `fetchBalance()`, `listServiceAgreements()`, `listPlannedPricelistChanges()` |
| Notifications | `fetchGeneralNotifications()`, `fetchShippingNotifications()`, `saveNotificationSettings()`, `fetchWebhookSettings()`, `listWebhookLogs()`, `checkWebhook()`, `resendWebhook()` |

Plus `Webhook\WebhookEvent`, which reads the notifications Furgonetka posts to your server (JSON and XML), checks
their checksum and builds the reply, and the local [validation](#local-validation) of shipments. Not covered: the
freight exchange (`/auctions`) and the account-administration endpoints (members, sessions, package templates).
The sections below list every method, object and rule.

## Conventions

- Request objects (`ShipmentRequest`, `Pickup`, `Receiver`, `AddressBookRequest`, ...) are immutable. Their
  `toArray()` leaves out what is null. Where the documentation states a rule that Furgonetka also enforces, the
  constructor refuses a value that breaks it (see [Local validation](#local-validation)).
- Response objects are lenient: every field is nullable, and an unknown field is ignored. Top-level objects
  keep the whole answer in `raw()`, so a field the library does not model yet is still there.
- Enums in answers are read with `tryFrom()`. A value this release does not know reads as `null`, and the
  object keeps the raw text next to it (`state` and `stateValue`, `carrier` and `carrierName`). Furgonetka adds
  values without notice, so branch on the enum and log the raw value.
- Ids of shipments are strings everywhere (Furgonetka sends numbers in some answers and text in others). A
  method that takes a shipment accepts a `Package`, a numeric string or an int. A waybill number is not an id.
- Dates without an offset in answers are read as Europe/Warsaw time. A `DateTimeInterface` or a
  `YYYY-MM-DD` string is accepted where a day is asked for.
- Empty strings in answers read as `null`.
- Secrets (`clientSecret`, passwords, tokens, the webhook token, an IBAN) are marked
  `#[\SensitiveParameter]`, shown as `[redacted]` in dumps and masked in `TransportRequest::redacted()`, so they
  do not reach a stack trace, a log or an exception message.

## Building a client

```php
$client = FurgonetkaApiClient::create(
    FurgonetkaApiConfig::fromEnv(),                       // FURGONETKA_CLIENT_ID, FURGONETKA_CLIENT_SECRET, ...
    new FileTokenStore('/var/lib/shop/furgonetka.json'),  // or a TokenProviderInterface
    logger: $psrLogger,
);
```

| Piece | What it is |
|-------|-----------|
| `FurgonetkaApiConfig` | Environment (`sandbox()`, `production()`, `fromEnv()`, `fromArray()`), language of Furgonetka's messages (`Language::English` by default), company id, timeouts, debug flag. `getBaseUrl()` and `url()` give the addresses. |
| `TokenStoreInterface` | `load()`, `save()` and `synchronized(callable)`: where the token lives and the lock around a refresh. `FileTokenStore` (a 0600 file, `flock`) and `InMemoryTokenStore` ship with the library. Write your own over a database or Redis. |
| `TokenProviderInterface` | `accessToken()` and `invalidate()`. `StaticTokenProvider` serves a token you manage yourself. A store given to `create()` becomes a `TokenManager`. |
| `ReauthorizerInterface` | Optional. `PasswordReauthorizer` logs in again by itself when the refresh token is dead. It needs the password in the environment and cannot pass a two-factor code, so it is off unless you give it to `create()`. |
| `TransportInterface` | `send(TransportRequest): TransportResponse`. `CurlTransport` is the default; TLS is always verified and redirects are not followed. |
| `PollingPolicy` | How long and how often a waiting method reads a command: `interval` 1 s, `backoff` 1.0, `maxInterval` 10 s, `timeout` 60 s. |
| `SleeperInterface` | What waits between reads; replace it in tests. |

The constructor takes the same pieces; `create()` builds the default ones. `withCompany(?int)` returns a client
for another company of the same user, sharing tokens, transport and logger.

## Commands

Ordering, pickups, cancellation, documents, shipment creation, file uploads, CSV export, cart re-check, HS codes,
adding to tracking and the webhook check and resend are **commands**: the client sends
`PUT /<x>-command/{uuid}` under a UUID and reads `GET` under the same UUID until the status is final.

Each command has three methods:

| Method | Does |
|--------|------|
| `orderPackages(...)` (the plain name) | submits, waits (`PollingPolicy`), returns the result |
| `submitOrder(...)` | submits and returns the UUID at once |
| `fetchOrderCommand($uuid)` | reads the state once |

- The UUID may be yours (`uuid:` argument). After a network error, call again with the same UUID: Furgonetka
  answers 400 `commandExists`, which the client takes as "already submitted" **only for a UUID the caller chose**,
  and reads the result instead of doing it twice. The exception of a network failure carries the UUID
  (`commandUuid()`).
- `CommandStatus`: `queueing`, `running`, `successful`, `partial_success`, `error`, and `cancelled` (an abort that
  landed while the command was queued; not in Furgonetka's documentation). The last four are final.
- A carrier's rejection is not an exception. Every result extends `CommandResult`: `status`, `statusValue`,
  `datetimeChange`, `errors`, `isFinished()`, `isSuccessful()`, `isPartial()`, `hasFailed()`, `isCancelled()`,
  `errorsFor($packageId)`, `failedPackageIds()`, `generalErrors()`, `raw()`. An error's `path` is
  `/packages/id/{id}` in a result, and `/packages/0/id` in the 400 of a submit.
- A status that is not one of the six is not final: the wait ends in `FurgonetkaApiCommandTimeoutException` with
  `commandUuid()` and `lastStatus()`.
- `abortCommand($uuid, CommandType)` lands only while the command is queued.
- A `409` on ordering or booking a pickup means the carrier's regulations are not accepted, see
  `pendingRegulations()` and `acceptRegulations()`. The library never accepts them by itself.

## Client methods

### Setup, login and quota

#### `static create(FurgonetkaApiConfig $config, TokenStoreInterface|TokenProviderInterface|null $tokens = null, ?LoggerInterface $logger = null, ?TransportInterface $transport = null, ?ReauthorizerInterface $reauthorizer = null, ?SleeperInterface $sleeper = null, ?PollingPolicy $polling = null): self`

Build a client with the default transport (cURL) and, when given a token store, a TokenManager that keeps the session alive.

#### `withCompany(?int $companyId): self`

The same client acting for another company (or for none, with null); tokens, transport and logger are shared.

#### `oauth(): OAuthClient`

The OAuth client, for the grants that do not store anything (authorization URL, client credentials) and for a token you want to manage yourself.

#### `connectWithPassword(string $username, string $password): void`

Log in with the account's username and password and keep the tokens. For a shop on its own account, once; every call opens one of the 20 sessions Furgonetka allows, so never per request.

#### `connectWithTwoFactor(string $hint, string $username, string $twoFactorCode): void`

Finish a login that stopped at the two-factor request and keep the tokens.

#### `connectWithAuthorizationCode(string $code, string $state, string $expectedState): void`

Finish the authorization code flow in your callback and keep the tokens. Use a client with a token store of its own for each connected account.

#### `lastRateLimit(): ?RateLimitStatus`

The request quota reported with the last answer, or null before the first one.

### Account

#### `listServices(): ServiceList`

The carrier contracts of the account. Remembered by this instance, and local validation uses them from then on to know the carrier and the owner of a `service_id`.

#### `listCompanies(): array`

The companies of the user. Use an `id` with withCompany().

#### `fetchAllowedCountries(): AllowedCountries`

Countries a shipment can go from and to. Needs no login. Remembered by this instance for local validation.

#### `fetchDictionary(): Dictionary`

Furgonetka's dictionary of carriers, services and field names. Needs no login.

### Regulations

#### `listRegulations(?string $type = null, ?bool $companyOwnerRegulations = null): array`

Every regulation and consent of the account, accepted or not. It holds about forty entries, most of them no shipment depends on, so do not accept "everything not accepted": see pendingRegulations().

#### `regulationsForPackages(array $packageIds): array`

The services whose regulations must be accepted before these shipments can be ordered (`servicesRegulation`); empty when nothing is pending.

#### `pendingRegulations(array $packageIds): array`

The regulations that stand between these shipments and ordering: the ones of the services that regulationsForPackages() names and that the account has not accepted. Show their documents to whoever has the authority to agree, then pass the accepted ones to acceptRegulations(). There is no variant without shipments on purpose: the answer would be every consent that is not accepted, and accepting all of them means agreeing to about forty documents nobody asked for.

#### `acceptRegulations(array $regulations): array`

Accept these regulations, in the exact version Furgonetka sent. **This is a legal act of the account, so it only ever happens on your explicit call.**

### Validation and prices

#### `validateLocally(ShipmentRequest $request, ?Carrier $carrier = null): array`

Run the local rules without any request. An error is a rule Furgonetka enforces too; the rest is in warningsFor(). The carrier is taken from the argument, else from listServices() if you called it, else the carrier rules are skipped.

#### `warningsFor(ShipmentRequest $request, ?Carrier $carrier = null): array`

Rules the documentation states that the API accepts a violation of. Never thrown; logged at every create and update.

#### `validateRemotely(ShipmentRequest $request): array`

Ask Furgonetka whether it would accept the shipment (`POST /packages/validate`). Nothing is created. The answer is the list of field errors: empty when the shipment is valid. Anything else that goes wrong (no login, no network) is an exception as usual.

#### `calculatePrices(ShipmentRequest $request, PriceScope $scope): PriceQuoteList`

Price a shipment with several carriers at once (`POST /packages/calculate-price`). **A carrier that cannot carry the shipment is not an error**: it comes back marked unavailable with the reason. Use PriceQuoteList::cheapest(), which skips those. No local validation runs: Furgonetka answers per carrier, and a price does not need the data a shipment does.

### Shipments

#### `createPackage(ShipmentRequest $request): Package`

Create a shipment (`POST /packages`). It is in the "waiting" state until it is ordered.

#### `updatePackage(Package|string|int $packageId, ShipmentRequest $request): Package`

Replace the data of a shipment that has not been ordered (`PUT /packages/{id}`). The whole shipment is sent again, not a difference.

#### `fetchPackage(Package|string|int $packageId, ?bool $originalServices = null, ?string $type = null): Package`

Read one shipment (`GET /packages/{id}`). Furgonetka answers 400, not 404, for a shipment that is not yours or has been deleted.

#### `listPackages(?PackageListQuery $query = null): PackagePage`

One page of shipments (`GET /packages`); see PackageListQuery for paging and filters.

#### `deletePackage(Package|string|int $packageId): void`

Delete a shipment that has not been ordered (`DELETE /packages/{id}`). An ordered one has to be cancelled.

#### `deletePackages(array $packageIds): void`

Delete several shipments that have not been ordered (`DELETE /packages`).

#### `createPackageAsync(ShipmentRequest $request, ?string $uuid = null, ?PollingPolicy $policy = null): CreatePackageCommandResult`

Create a shipment as a command and wait for the result (`PUT /create-package-command/{uuid}`). The same shipment as createPackage(), made the way the other actions are: submitted under a UUID and read until it is finished, so a retry after a network failure cannot create it twice (submit again with the same UUID, see submitOrder()). The local rules run first, as for `createPackage()`. The command can answer with `redirectUrl` instead of a shipment, where a person finishes it in the browser; the sandbox never did.

#### `submitCreatePackage(ShipmentRequest $request, ?string $uuid = null): string`

Submit the creation of a shipment and return the UUID without waiting; a repeat with the same UUID is safe, see submitOrder().

#### `fetchCreatePackageCommand(string $uuid): CreatePackageCommandResult`

Read the state of a shipment creation (`GET /create-package-command/{uuid}`) without waiting.

### Ordering

#### `orderPackages(array $packageIds, ?LabelOptions $label = null, bool $skipEmail = false, ?string $uuid = null, ?PollingPolicy $policy = null): OrderCommandResult`

Order shipments and wait for the result. Submits the order under a fresh UUID and reads it until it is finished, see submitOrder() and fetchOrderCommand() for the two halves. **A carrier that refuses a shipment does not throw**: look at OrderCommandResult::isSuccessful() and `errorsFor($id)`. What does throw: a 409 when the carrier's regulations are not accepted (pendingRegulations()), a 400 `noSignature` for a shipment that leaves the EU until the sender has signed the customs documents on Furgonetka's own page (`Package::signatureRequired()` and `documentsSigned()` say where it stands; the API has no call for a signature), a 400 without a code on `/packages` when the price is above the balance, and a timeout, which carries the UUID so the order is read later instead of being placed twice.

#### `submitOrder(array $packageIds, ?LabelOptions $label = null, bool $skipEmail = false, ?string $uuid = null): string`

Submit an order and return its UUID without waiting. **A retry is safe when you pass the UUID.** If the request fails on the network you cannot know whether Furgonetka got it. Submit again with the same UUID: Furgonetka answers 400 with the code `commandExists` when it did, and this method then just returns the UUID (checked on the sandbox on 2026-09-29). A UUID the client generates is never reused.

#### `fetchOrderCommand(string $uuid): OrderCommandResult`

Read the state of an order (`GET /order-commands/{uuid}`) without waiting.

#### `sendDocumentsEmail(Package|string|int $packageId): void`

Send the confirmation e-mail of an ordered shipment (`POST /packages/send-email`): for shipments ordered with `skipEmail`, or after a pickup was added. Once, up to 18 hours after ordering.

#### `revalidatePackages(array $packageIds, ?string $uuid = null, ?PollingPolicy $policy = null): RevalidateResult`

Check the shipments in the cart again and wait for the verdict (`PUT /revalidate-command/{uuid}`). `success` of the result says whether they pass. Furgonetka answers 400 with `packageOwner` to a shipment that is not yours.

#### `submitRevalidate(array $packageIds, ?string $uuid = null): string`

Submit a cart check and return the UUID without waiting; a repeat with the same UUID is safe.

#### `fetchRevalidate(string $uuid): RevalidateResult`

Read the state of a cart check (`GET /revalidate-command/{uuid}`) without waiting.

### Labels, documents and the protocol

#### `fetchLabel(Package|string|int $packageId, ?LabelPageFormat $pageFormat = null): Label`

Download the label of a shipment (`GET /packages/{id}/label`): a PDF, or a ZPL or EPL text.

#### `requestDocuments(array $packageIds, array $types, ?LabelOptions $label = null, ?string $uuid = null, ?PollingPolicy $policy = null): DocumentsCommandResult`

Prepare documents of shipments and wait for the file, see submitDocuments().

#### `submitDocuments(array $packageIds, array $types, ?LabelOptions $label = null, ?string $uuid = null): string`

Submit a documents command and return its UUID without waiting; a repeat with the same UUID is safe, see submitOrder().

#### `fetchDocumentsCommand(string $uuid): DocumentsCommandResult`

Read the state of a documents command (`GET /documents-command/{uuid}`) without waiting.

#### `downloadDocuments(DocumentsCommandResult|string $source): DocumentFile`

Download the file of a finished documents command. The link is signed and needs no login, and no token is sent: the file lives on another host than the API, and a bearer token must not leave the API. Only https links are followed.

#### `fetchProtocol(array $packageIds): DocumentFile`

The pickup protocol (a PDF) for shipments (`POST /packages/protocol`). Available for DPD (domestic), DHL, InPost Kurier and InPost parcel lockers on an own contract, FedEx (domestic) and GLS (own contract).

### Courier pickup and cancellation

#### `fetchPickupProposals(array $packageIds, DateTimeInterface|string|null $readyDate = null, ?bool $onlyRepickup = null, ?int $daysAhead = null): array`

The days and time windows in which the courier could collect (`POST /packages/pickup-date-proposals`).

#### `orderPickup(array $packageIds, PickupDate $pickupDate, ?string $uuid = null, ?PollingPolicy $policy = null): PickupCommandResult`

Book a courier pickup and wait for the result, see submitPickup(). Takes a slot from fetchPickupProposals() through DtoPickupProposal::toPickupDate(). Like ordering, it answers 409 when the carrier's regulations are not accepted.

#### `submitPickup(array $packageIds, PickupDate $pickupDate, ?string $uuid = null): string`

Submit a pickup command and return its UUID without waiting; a repeat with the same UUID is safe, see submitOrder().

#### `fetchPickupCommand(string $uuid): PickupCommandResult`

Read the state of a pickup command (`GET /pickup-commands/{uuid}`) without waiting.

#### `cancelPackages(array $packageIds, ?string $uuid = null, ?PollingPolicy $policy = null): CancelCommandResult`

Cancel ordered shipments and wait for the result, see submitCancel(). Cancelling can cost money (GLS), and may be scheduled instead of done at once: read each detail of the result.

#### `submitCancel(array $packageIds, ?string $uuid = null): string`

Submit a cancellation and return its UUID without waiting; a repeat with the same UUID is safe, see submitOrder().

#### `fetchCancelCommand(string $uuid): CancelCommandResult`

Read the state of a cancellation (`GET /cancel-command/{uuid}`) without waiting.

#### `markAsCancelled(Package|string|int $packageId): void`

Mark a shipment on an own contract as cancelled without telling the carrier (`POST /packages/mark-as-cancelled`), for a shipment you cancelled with the carrier yourself.

#### `abortCommand(string $uuid, CommandType $type = CommandType::Generic): void`

Abort a command that has not started. On the sandbox a command that was still queued answered 204 and ended as `cancelled` (CommandResult::isCancelled()); one that had started (commands take seconds) or had finished answered 400 (`wrongState`), and an unknown one 400 (`commandNotExists`). Both the typed endpoint and the generic one aborted a CSV export.

### Tracking

#### `fetchTracking(Package|string|int $packageId): TrackingResult`

Read the tracking of a shipment (`GET /packages/{id}/tracking`, version 2): one history per parcel, keyed by waybill number. The endpoint takes Furgonetka's number of the shipment. A waybill number is a 404 there, so it is refused before a request is made. A shipment that was ordered but has not moved answers with its parcels and empty histories.

#### `addToTracking(string $waybillNumber, Carrier|string|null $carrier = null, ?string $name = null): TrackedPackage`

Follow a shipment that did not go through Furgonetka (`POST /packages/add-to-tracking`). The answer carries the id to give fetchTracking(). Furgonetka answers 400 with the code `exists` for a number that is already on the list and `existsSent` for one that is among the account's ordered shipments. A tracked package cannot be removed through the API.

#### `renameTrackedPackage(Package|string|int $packageId, string $name): TrackedPackage`

Change the name of a tracked package (`PUT /packages/edit-tracked-package/{package_id}`).

#### `simulateTracking(Package|string|int $package, TrackingState $state, ?string $waybillNumber = null): void`

Set the tracking state of an ordered shipment, to try tracking and webhooks without a real courier (`PUT /packages/tracking/simulate`). **Sandbox only.** The specification lets either the shipment id or the waybill number do; the sandbox wants both, so both are sent. With a Package the first waybill number is used; with an id the number has to be given. The sandbox replaces the whole history with events leading to the state, ten minutes apart, so the state you set last is the latest one. It stays ordered: once a delivery state was set, cancelling the shipment is refused ("In package tracking found delivery status").

#### `addToTrackingAsync(string $waybillNumber, Carrier|string|null $carrier = null, ?string $name = null, ?string $uuid = null, ?PollingPolicy $policy = null): AddToTrackingCommandResult`

Add a shipment to tracking as a command and wait (`PUT /add-package-to-tracking-command/{uuid}`). The command works out the carrier when you leave it out, which took about five seconds on the sandbox, and answers with the tracked shipment's id. A number that is already tracked ends as `partial_success` with the code `package_already_exists_in_tracking`, not as an exception.

#### `submitAddToTracking(string $waybillNumber, Carrier|string|null $carrier = null, ?string $name = null, ?string $uuid = null): string`

Submit adding a shipment to tracking and return the UUID without waiting; a repeat with the same UUID is safe.

#### `fetchAddToTrackingCommand(string $uuid): AddToTrackingCommandResult`

Read the state of adding a shipment to tracking (`GET /add-package-to-tracking-command/{uuid}`) without waiting.

### Pickup points

#### `findPoints(PointsQuery $query): PointList`

Search pickup points (`POST /points/map`). Needs no login. The points come nearest first. A phrase Furgonetka cannot place is not an error: the sandbox fell back to a default centre in Warsaw, so read PointList::$coordinates and the `distance` of the points. A request without a usable location answers 404 with the path `/location`, which is an exception like any other. Use `Point::identifier()` as `Receiver::$point` to send a shipment to a point.

### Returns, readdressing and the "send to me" link

#### `calculateReturnPrice(Package|string|int $packageId): PriceEstimate`

What returning an ordered shipment to its sender would cost (`POST /packages/return/calculate-price`). Furgonetka answers 400 with the code `notPossible` ("This package cannot be returned") for a shipment that cannot be returned; on the sandbox that was every shipment it was tried on, so a price itself has not been seen. A shipment the API does not know is `packageDoNotExist`.

#### `returnPackage(Package|string|int $packageId, string|int|null $regulationsAccept = null, ?string $uuid = null): PaymentRequest`

Ask for the return of an ordered shipment (`PUT /packages/return/{uuid}`). **This does not pay or confirm anything, and it does not wait.** The answer carries the links where a person pays and confirms. Furgonetka has no status call for it (the one for a return from a point is out of scope), so a repeated request with the same UUID is a 400 `commandExists` and the links are not sent again.

#### `calculateReaddressingPrice(Package|string|int $packageId, ReaddressingReceiver $receiver, string|int|null $regulationsAccept = null): PriceEstimate`

What sending an ordered shipment to another receiver would cost (`POST /packages/readdressing/calculate-price`). It also validates the receiver, so the field errors come back before anything is ordered. Not answered with a price on the sandbox: every shipment was "not possible" (`notPossible` on `/packageId`), and the field errors of the receiver came back next to it.

#### `readdressPackage(Package|string|int $packageId, ReaddressingReceiver $receiver, Carrier|string|null $service = null, string|int|null $regulationsAccept = null, ?string $uuid = null): PaymentRequest`

Ask for a shipment to go to another receiver (`PUT /packages/readdressing/{uuid}`). Like returnPackage() it returns the payment and confirmation links at once and waits for nothing. Read where it stands with fetchReaddressing(), withdraw it with cancelReaddressing().

#### `fetchReaddressing(string $uuid): ReaddressingCommandResult`

Read the state of a readdressing (`GET /packages/readdressing/{uuid}`): the new receiver, the price and whether it is paid.

#### `cancelReaddressing(string $uuid): void`

Withdraw a readdressing order (`DELETE /packages/readdressing/{uuid}`).

#### `createSendToMeLink(SendToMeRequest $request): SendToMeLink`

A link to the shipment form with the receiver's data already filled in (`POST /packages/send-to-me-link`). Needs no login: Furgonetka validates the data and builds the link, and the person who opens it logs in and finishes the shipment.

### Files

#### `uploadFile(string $filename, string $contents, ?string $type = null, ?string $contentType = null, ?string $uuid = null, ?PollingPolicy $policy = null): FileUploadResult`

Upload a file and wait until Furgonetka has stored it (`POST /file-command/{uuid}`). A shipment refers to an uploaded file by UUID (`ShipmentRequest::$photos`, `ShipmentRequest::$filesUuids`, `Duty::$documentsUuids`); the result carries both the UUID of the upload and the one of the stored file, For photos (`type` `packagePhoto`, the one Furgonetka's own panel sends) a shipment takes the stored file's UUID, see FileUploadResult. The upload is sent with your token although the specification lists it as public: a file uploaded without it belongs to nobody and its status cannot be read afterwards (checked on the sandbox). Storing succeeds for any file and any `type`, so a successful result says nothing about whether a shipment will accept the file: a photo uploaded without the type `packagePhoto` was refused by a shipment under both UUIDs.

#### `submitFileUpload(string $filename, string $contents, ?string $type = null, ?string $contentType = null, ?string $uuid = null): string`

Send a file and return the UUID of the upload without waiting; a repeat with the same UUID is safe, see submitOrder(). Read the result with fetchFileUpload().

#### `fetchFileUpload(string $uuid): FileUploadResult`

Read the state of one upload (`GET /file-command`) without waiting.

#### `fetchFileUploads(array $uuids): array`

Read the state of several uploads at once (`GET /file-command`, up to 50). **One UUID the account does not know fails the whole call**: Furgonetka answers 400 (`valueTypeNotValid` on `/uuid`) and names that UUID. That is also what happens to a file uploaded without a token. The entries are not in the order you asked in, so they come keyed by UUID.

### Address book

#### `listAddressBook(?AddressBookQuery $query = null): AddressBookPage`

A page of the address book (`GET /account/address-book`), 50 entries a page.

#### `fetchAddressBookEntry(AddressBookEntry|int $id): AddressBookEntry`

One entry of the address book (`GET /account/address-book/{id}`). An entry that does not exist is a 400 with the code `notExists`.

#### `createAddressBookEntry(AddressBookRequest $request): AddressBookEntry`

Add an entry to the address book (`POST /account/address-book`). The answer is the entry as Furgonetka stored it, normalised (phone with its prefix, city with its accents).

#### `replaceAddressBookEntry(AddressBookEntry|int $id, AddressBookRequest $request): void`

Replace an entry (`PUT /account/address-book/{id}`). **It is replaced whole**: what the request does not carry is emptied, which the sandbox showed by wiping the name, e-mail, address and phone of an entry that was sent only a new company name. Start from the entry and change what you need: $entry = $client->fetchAddressBookEntry(380861); $client->replaceAddressBookEntry($entry, $entry->toRequest()->with(['city' => 'Krakow']));

#### `deleteAddressBookEntries(array $entries): void`

Delete entries (`DELETE /account/address-book`). Furgonetka refuses the whole call when one id is unknown or when the list is empty (400 on `/entries/0/id` or `/entries/0`).

#### `validateAddressBookEntry(AddressBookRequest $request): array`

Ask Furgonetka whether it would take an entry (`POST /account/address-book/validate`). Nothing is stored. The answer is the list of field errors, empty when the entry is fine, like validateRemotely().

### CSV export and HS codes

#### `exportPackagesCsv(CsvRange $range, array $packageIds = [], ?string $uuid = null, ?PollingPolicy $policy = null): CsvExportResult`

Export sent shipments as a CSV and wait for the file (`PUT /csv-command/{uuid}`). The result's `url` is a signed link; downloadCsvExport() fetches it.

#### `submitCsvExport(CsvRange $range, array $packageIds = [], ?string $uuid = null): string`

Submit a CSV export and return the UUID without waiting; a repeat with the same UUID is safe, see submitOrder().

#### `fetchCsvExport(string $uuid): CsvExportResult`

Read the state of a CSV export (`GET /csv-command/{uuid}`) without waiting.

#### `downloadCsvExport(CsvExportResult|string $source): DocumentFile`

Download the CSV of a finished export, the way downloadDocuments() downloads documents: a signed https link on another host, no token sent.

#### `searchHsCodes(HsCodeService|string $service, string $description, ?string $uuid = null, ?PollingPolicy $policy = null): HsCodeSearchResult`

Search the customs tariff for HS codes and wait for the list (`PUT /packages/duty/hs-codes-autocomplete-command/{uuid}`). Needs a client with a token (the endpoint stopped answering without one on 2026-09-29; a client with no token refuses the call locally). A carrier the account may not use ends as an `error` result, not as an exception.

#### `submitHsCodeSearch(HsCodeService|string $service, string $description, ?string $uuid = null): string`

Submit an HS code search and return the UUID without waiting; a repeat with the same UUID is safe.

#### `fetchHsCodeSearch(string $uuid): HsCodeSearchResult`

Read the state of an HS code search (`GET /packages/duty/hs-codes-autocomplete-command/{uuid}`) without waiting.

### Notifications and the webhook

#### `fetchGeneralNotifications(): GeneralNotifications`

The general notification options (`GET /account/settings/notification/general`).

#### `fetchShippingNotifications(): ShippingNotifications`

The shipping notification settings, one group per tracking state (`GET /account/settings/notification/shipping`).

#### `saveNotificationSettings(NotificationSettingsRequest $request): void`

Save the notification settings (`POST /account/settings/notification`). **This replaces all of them**, the webhook included, so build the request from a read, see NotificationSettingsRequest. It is logged; the webhook token is not.

#### `fetchWebhookSettings(): WebhookSettings`

The webhook the account has set up (`GET /account/settings/notification/webhook`). An account with none answers with everything null, see WebhookSettings::isConfigured(). The token in the answer is a secret.

#### `listWebhookLogs(?WebhookLog $after = null): WebhookLogPage`

The calls Furgonetka made to the webhook and how the receiver answered (`GET /account/settings/notification/webhook/logs`).

#### `checkWebhook(string $url, string $token, WebhookFormat $format, ?string $uuid = null, ?PollingPolicy $policy = null): WebhookCommandResult`

Ask Furgonetka to call a webhook address and wait for the verdict (`PUT /account/settings/notification/webhook/check-command/{uuid}`). Nothing is saved: the address is only tried. An address that cannot be reached ends as `error` with no errors listed.

#### `submitWebhookCheck(string $url, string $token, WebhookFormat $format, ?string $uuid = null): string`

Submit a webhook check and return the UUID without waiting; a repeat with the same UUID is safe.

#### `fetchWebhookCheck(string $uuid): WebhookCommandResult`

Read the state of a webhook check (`GET .../webhook/check-command/{uuid}`) without waiting.

#### `resendWebhook(WebhookLog|int $log, ?string $uuid = null, ?PollingPolicy $policy = null): WebhookCommandResult`

Send a notification from the webhook log again and wait (`PUT /account/settings/notification/webhook/resend-command/{uuid}`). Furgonetka answers 400 with the code `invalid` on `/id` for a log entry it does not have.

#### `submitWebhookResend(WebhookLog|int $log, ?string $uuid = null): string`

Submit a resend and return the UUID without waiting; a repeat with the same UUID is safe.

#### `fetchWebhookResend(string $uuid): WebhookCommandResult`

Read the state of a resend (`GET .../webhook/resend-command/{uuid}`) without waiting.

### Balance and price lists

#### `fetchBalance(?BalanceQuery $query = null): Balance`

The account's operations and its balance (`GET /account/balance`), newest first. See BalanceQuery for the filters and what the sandbox showed about them.

#### `listServiceAgreements(): array`

The contracts of your own that the account has with carriers (`GET /account/services-agreements`). Empty for an account that only uses Furgonetka's contracts. Use `serviceId` as the `service_id` of a shipment.

#### `listPlannedPricelistChanges(): array`

The changes of carriers' price lists that Furgonetka has announced (`GET /account/planned-pricelist-changes`). Empty when none is planned.

## Requests

| Object | For | Notes |
|--------|-----|-------|
| `ShipmentRequest` | create, update, validate, price | `serviceId`, `pickup`, `receiver`, `parcels`, and optionally `userReferenceNumber`, `labelSender`, `additionalServices`, `duty`, `payer`, `filesUuids`, `photos`, `extra` (fields the library does not model yet; not validated locally) |
| `Pickup`, `Receiver` | the two ends | name or company, street, postcode, city, country (default PL), county, e-mail, phone, or a pickup `point` code |
| `LabelSender` | `ShipmentRequest::$labelSender` | "other data on the label"; sent as `sender`. Returns go to this address |
| `Parcel` | a parcel | `Parcel::package(w, d, h, weight, value, description)`; `ParcelType` is per parcel in v2 |
| `AdditionalServices`, `Cod`, `Duty`, `ExportItem`, `Payer` | services, cash on delivery, customs, who pays | 45 additional services are modelled |
| `PriceScope` | `calculatePrices()` | `PriceScope::carriers(Carrier::Dpd, ...)` or `PriceScope::serviceIds(...)` |
| `LabelOptions` | ordering, documents | `LabelOptions::pdf(LabelPageFormat::A6)`, `zpl()`, `epl()` |
| `PickupDate` | `orderPickup()` | `PickupProposal::toPickupDate()` carries the `hash` of the proposal |
| `ReaddressingReceiver`, `SendToMeRequest` | readdressing, the "send to me" link | |
| `AddressBookRequest` | the address book | `with([...])` copies with changes; `AddressBookEntry::toRequest()` starts from an entry |
| `NotificationSettingsRequest` | `saveNotificationSettings()` | `from($shipping, $general, $webhook)`, then `withOrder()`, `withTracking()`, `withEmail()`, `withWebhook()` |
| `OrderNotificationRule`, `TrackingNotificationRule`, `EmailNotifications` | the parts of it | |

## Queries

| Object | For | Filters |
|--------|-----|---------|
| `PackageListQuery` | `listPackages()` | `limit`, `lastPackageId` (paging: the id of the last shipment of the previous page), `listType` (`ListType`), `query`, `filters` (`PackageFilters`: services, dates, statuses, COD, ids, ...) |
| `PointsQuery` | `findPoints()` | a place (coordinates, a phrase or an address), carriers (`services`, required), `maxDistanceKm`, `pointTypes` (`PointFilterType`), `limit` 1 to 2000, `mapBounds`; `PointsQuery::forPoint($id, $services)` |
| `AddressBookQuery` | `listAddressBook()` | `page`, `search`, `ids`, `onlyDefaultPickup` |
| `BalanceQuery` | `fetchBalance()` | `page`, `lastId`, `items` 1 to 1000, `effect` (`BalanceEffect`), `dateStart`, `dateEnd` (days), `group` (`BillGroup`) |

## Results

| Object | From |
|--------|------|
| `Package`, `PackagePage`, `PackageParcel`, `PackageAddress` | `createPackage()`, `fetchPackage()`, `listPackages()`; `Package::isOrdered()`, `waybillNumbers()`, `labelSender`, `signatureRequired()` and `documentsSigned()` (the customs signature outside the EU, null when the shipment has no customs data), `returnDisposition()` and `readdressingDisposition()` (null until a return or readdressing is ordered) |
| `ReturnDisposition`, `ReaddressingDisposition` | `Package::returnDisposition()`, `readdressingDisposition()`: status (`DispositionStatus`), creation time and, for a readdressing, the new receiver as flat fields; built from the specification, never seen filled in |
| `PriceQuoteList`, `PriceQuote`, `Pricing`, `PricingDetail`, `CourierDetails` | `calculatePrices()`; a carrier that cannot take the parcel has `available` false with its own errors; `cheapest()` skips those |
| `ServiceList`, `Service` | `listServices()`; `first(Carrier::Dpd)`, `forCarrier()`, `find()` |
| `Regulation`, `RegulationDocument` | the regulations; `Regulation::toAcceptance()` |
| `OrderCommandResult`, `PickupCommandResult`, `CancelCommandResult`, `DocumentsCommandResult`, `CreatePackageCommandResult`, `FileUploadResult`, `CsvExportResult`, `RevalidateResult`, `AddToTrackingCommandResult`, `HsCodeSearchResult`, `WebhookCommandResult`, `ReaddressingCommandResult` | the commands, all `CommandResult` |
| `Label`, `DocumentFile` | `fetchLabel()` (PDF, ZPL or EPL), the documents, protocol and CSV downloads |
| `PickupProposalGroup`, `PickupProposal` | `fetchPickupProposals()` |
| `TrackingResult`, `ParcelTracking`, `TrackingEvent`, `TrackedPackage` | `fetchTracking()` and friends; `numbers()`, `parcel($number)`, `latest()`, `isDelivered()` |
| `PointList`, `Point`, `PointAddress`, `Coordinates`, `OpeningHours`, `BoxSpecification`, `MapBounds` | `findPoints()`; a point is identified by `Point::identifier()` |
| `PriceEstimate`, `PaymentRequest`, `ReaddressingDetails`, `SendToMeLink` | returns and readdressing |
| `AddressBookPage`, `AddressBookEntry` | the address book |
| `GeneralNotifications`, `ShippingNotifications`, `ShippingNotificationGroup`, `OrderChannels`, `TrackingChannels`, `WebhookSettings`, `WebhookLog`, `WebhookLogPage` | notifications and the webhook |
| `Balance`, `Bill`, `BillPackage`, `ServiceAgreement`, `PricelistChange` | billing |
| `HsCode`, `Dictionary`, `AllowedCountries`, `Company`, `RateLimitStatus`, `ApiError`, `PhoneNumber` | the rest |

## Enums

`AgreementOwner`, `BalanceEffect`, `BillGroup`, `BillProduct`, `BillType`, `Carrier` (30 carriers of the dictionary),
`CsvRange`, `DispositionStatus`, `DocumentType`, `DutyDocumentsOwner`, `DutyType`, `HsCodeService`, `InvoiceDataType`, `LabelFileFormat`,
`LabelPageFormat`, `Language`, `ListType`, `PackageState`, `ParcelState`, `ParcelType`, `PointFilterType`,
`PointService`, `PointServiceType`, `PriceScenario`, `ReaddressingStatus`, `TrackingState`, `WebhookFormat`, and
`Command\CommandStatus` and `Command\CommandType`.

A method that takes a carrier accepts a `Carrier` or a string, so a carrier Furgonetka added after this release
still works: `addToTracking('...', 'newcarrier')`. The string must be an identifier such as `dpd`.

## Local validation

`createPackage()`, `updatePackage()` and `createPackageAsync()` run the local rules first and throw
`FurgonetkaApiValidationException` (`$errors`, a list of `"/path: message"`) without sending anything. Two methods
run them on demand, with no request:

- `validateLocally($request, ?Carrier, ...)` returns **errors**: rules that Furgonetka enforces too (checked on the
  sandbox), so an error here is an error there.
- `warningsFor($request, ?Carrier)` returns **warnings**: rules the documentation states that the API accepts a
  violation of, or that could not be checked. They are logged on every create and price and never thrown.

`validateRemotely($request)` asks Furgonetka (`POST /packages/validate`) and is the authority; an empty list means
valid. Rules that depend on the carrier run only when the carrier is known: pass it, or call `listServices()` first
on the same instance.

| Rule | Kind |
|------|------|
| service id positive, at least one parcel, street, postcode and city of pickup and receiver | error |
| name or company of pickup, receiver and label sender (except an InPost receiver) | error |
| first and last name of at least 2 characters each and 6 to 90 in all; company 2 to 80 (counted in characters) | error |
| name and company limits of DHL 60, Poczta 60, AmbroExpress 60, UPS 30, FedEx company 150 | error |
| county for IE, US, CA | error |
| number of parcels: DPD, GLS, FedEx and DHL 15; DPD envelope 1; DHL pallets 5; UPS on Furgonetka's contract 1; Poczta 10 | error |
| parcel description at least 2 characters; DHL at most 30; UPS at most 50 | error |
| at most one of the eight Poczta services; `cud` excludes `rod` | error |
| COD not above the sum of the parcel values (DHL, FedEx, Poczta) | error |
| `duty` for the countries that need customs documents, and an EORI for a sale | error |
| DPD reference number at most 36 characters | error |
| the documented parcel counts and text limits the API does not enforce | warning |
| label data (`sender`) for a carrier the documentation does not list; label data that differ from the pickup (returns go there) | warning |
| Saturday delivery needs a Friday pickup | warning |

Other requirements the sandbox showed and the library does not check (the remote validation reports them): label
data at DPD also need e-mail and phone, COD at DPD needs the account owner's name, a sale to GB needs a VAT number
and an invoice number, foreign phone numbers need a leading plus.

Other request objects refuse what would certainly be refused: `AddressBookRequest` needs a name or a company,
`BalanceQuery` a day in `YYYY-MM-DD` and `items` from 1 to 1000, `PointsQuery` a place and a carrier,
`NotificationSettingsRequest` non-empty lists and a format for an address, `SendToMeRequest` and
`ReaddressingReceiver` their required fields.

## Exceptions

```
FurgonetkaApiException                   getRawResponse(), getDebugReport()
+-- FurgonetkaApiValidationException     local rules; $errors
+-- FurgonetkaApiTransportException      cURL, TLS, timeout; commandUuid() for a command
+-- FurgonetkaApiResponseParseException  not JSON, wrong shape, an empty file
+-- FurgonetkaApiLabelNotReadyException  204 on a label or protocol
+-- FurgonetkaApiCommandTimeoutException no final status in time; commandUuid(), lastStatus()
+-- FurgonetkaApiOAuthException          error, errorDescription, statusCode; also state_mismatch
|   +-- FurgonetkaApiTwoFactorRequiredException        hint()
|   +-- FurgonetkaApiReauthorizationRequiredException  no usable token: log in again
+-- FurgonetkaApiHttpException           statusCode, errors, getFieldPaths(), hasErrorCode()
    +-- FurgonetkaApiBadRequestException 400
    +-- FurgonetkaApiAuthException       401 and 403
    +-- FurgonetkaApiNotFoundException   404
    +-- FurgonetkaApiConflictException   409: regulations not accepted
    +-- FurgonetkaApiGoneException       410
    +-- FurgonetkaApiRateLimitException  429; retryAfterSeconds()
    +-- FurgonetkaApiServerException     5xx; isRetryable()
```

Furgonetka answers errors in three shapes (`{"errors":[...]}`, `{"code","message","details"}` and the OAuth
`{"error","error_description"}`); all become one of the classes above. Match on `hasErrorCode()` and
`getFieldPaths()`, never on the message: `X-Language: en_GB` translates most messages but not all.

With `debug: true` in the configuration an exception carries the raw answer (`getRawResponse()`, with tokens
masked) and `getDebugReport()` adds the stack trace. Leave it off in production.

A `401` for a token from a store is repeated once with the token that is current then (another process may have
refreshed it a moment ago and the old access token dies at once); a second `401` is
`FurgonetkaApiAuthException`. A `StaticTokenProvider` has no other token to offer, so it gets no repeat.

## Webhook

`Webhook\WebhookEvent` reads a notification and answers for it:

| Method | Does |
|--------|------|
| `fromJson($body)`, `fromXml($body)`, `fromArray($payload, $format)` | parse; throws `FurgonetkaApiResponseParseException` for a body that is not a notification. XML must have a `Notification` root and no DOCTYPE; nothing is fetched and no entity is expanded |
| properties | `packageId`, `packageNo`, `partnerOrderId` (the shipment's `order_number`), `state` and `stateValue`, `description`, `datetime`, `branch`, `control`, `format` |
| `verify($token)` | recomputes the checksum: `md5(package_id . package_no . partner_order_id . state . description . datetime . branch . token)` over the values as sent |
| `acknowledgement(bool $accepted = true)` | the reply Furgonetka expects, as a `WebhookReply` (`body`, `contentType`, `statusCode`) |
| `raw()` | the notification as decoded |

The receiver has to answer HTTP 200 with `{"status":"OK"}` (JSON) or `<status>OK</status>` (XML); a plain "OK"
makes every check end as an error. The checksum is Furgonetka's own scheme, not a signature: it keeps out a
careless caller, and the notification is a hint to read `fetchTracking()`, not the record. The example
[11_webhook_endpoint.php](../examples/11_webhook_endpoint.php) is a complete receiver.

## Transport and testing

`TransportInterface::send(TransportRequest): TransportResponse` is the only thing the client needs from the
network, so a test passes a scripted implementation (see
[09_di_and_testing.php](../examples/09_di_and_testing.php)). `TransportRequest::redacted()` and
`TransportResponse::redacted()` mask `Authorization` and the secrets in the body, so a request can be logged;
`__debugInfo()` does the same for `var_dump()`.
