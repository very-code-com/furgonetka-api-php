# Furgonetka's quirks

What the Furgonetka.pl API does that its documentation does not say, or says differently. Everything here was
checked against the sandbox (`api.sandbox.furgonetka.pl`) on 29 September 2026, against the API of changelog
version 1.0.118; where something could not be checked, it says so. The library handles each of these; this page
is for the person who wonders why.

Furgonetka changes its API every few days. The registry of operations is compared with the published
specification every week (`tools/spec-drift.php`, run by `.github/workflows/spec-drift.yml`), so a removal, a
deprecation or a new version of an endpoint shows up as a red run.

## Tokens and sessions

- OAuth2 only. The `password` grant and the `authorization_code` grant both give an access token (about 30 days
  on the sandbox) with a refresh token; `client_credentials` gives 3600 seconds and no refresh token, and cannot
  reach a user's shipments.
- A refresh token is used once and rotates. Two processes that refresh at the same moment: one wins, the other
  loses the session. The token therefore has to live in a store with a lock, and the new refresh token has to be
  saved before anything else happens. That is `TokenManager` and `TokenStoreInterface::synchronized()`.
- A successful refresh kills the old access token at once (401). A request that was already on its way with
  the old token gets a 401 although nothing is wrong, so the client repeats it once with the current token.
- A spent refresh token answers 400 `invalid_grant` ("The refresh token is invalid", hint "Token has been
  revoked"); wrong login or password is 400 `invalid_grant` too; wrong application keys are 401 `invalid_client`.
- At most 20 sessions per application and user. Every `password` login opens one, and the 21st revokes the
  oldest, which may be a server of yours. Log in once per installation, not per request or per test run.
- `X-Language` does not change the messages of the token endpoint.
- The `code` of the authorization code flow lives 30 seconds and is single-use. The documentation asks you to run
  `urldecode()` on it after PHP has decoded `$_GET`; the codes the sandbox issued were lowercase hexadecimal, for
  which that changes nothing, and the library decodes as documented.
- A token knows the environment and the application it was issued for. A sandbox token is ignored, never
  refreshed and never sent, when the configuration says production.
- Two-factor login: the password grant answers `{"error":"2fa_required","hint":"..."}` and
  `connectWithTwoFactor()` finishes it. This could not be tried (the sandbox account has no 2FA), so it follows the
  documented response.

## Versions of the API

Each endpoint has versions, chosen by the media type: `Accept: application/vnd.furgonetka.v2+json` and, for a
request with a body, the same in `Content-Type`. v2 exists for shipments (create, update, read, list, validate,
price) and tracking; the other endpoints are v1.

- An unknown version is not an error, and no version is not either. For a request without a body `Accept`
  decides; for one with a body the newer of `Accept` and `Content-Type` wins. A number above the newest (`v3`,
  `v9`) is served as the newest; anything that is not a number, or is below 2 (`v0`, `vx`, `v2.5`), as v1. A typo
  in a version returns another shape of the same data and nothing tells you.
- The shapes differ: a v1 shipment has a top-level `type`, v2 has `type` per parcel; v1 tracking is
  `tracking` plus `additionalInfoTracking`, v2 is `parcels` keyed by waybill number.
- The answer says `Content-Type: application/json` whatever the version.

So the library keeps every version in one registry (`Internal\Http\Endpoint`), sends it in both headers, has a
test for each operation, and supports only the newest version of each endpoint.

## Commands

Ordering, pickups, cancelling, documents, shipment creation, files, CSV export, cart re-check, HS codes, adding to
tracking and the webhook check and resend are `PUT /<x>-command/{uuid}` with a UUID you choose, read with `GET`.

- Statuses: `queueing`, `running`, `successful`, `partial_success`, `error`, and **`cancelled`**, which the
  documentation does not list (an abort that landed on a queued command).
- A repeated `PUT` under the same UUID is 400 `commandExists` (path `/uuid`), and the command is not run a
  second time. That makes a retry after a network error safe. Repeating a pickup adds a second error, `sent`, on
  `/packages/0/id`.
- 409 on ordering and on booking a pickup is not "the UUID exists": it is `terms_and_conditions_not_valid`,
  the account has not accepted the carrier's regulations, and the body is flat (`{"code","message","details"}`,
  no `errors`). The sandbox account had accepted DPD and not FedEx.
- Ordering needs a verified phone number on the account ("Please verify Your phone number", path `/phone`).
- A carrier's rejection is a result, not an HTTP error. The command answers 200 with status `error` or
  `partial_success` and errors whose `path` is `/packages/id/{id}`. The 400 of a submit names the same shipment as
  `/packages/0/id`. Create-as-command errors have indexed paths of their own (`/0/postcode`).
- Aborting (`DELETE /<x>-command/{uuid}`, or the generic `/generic-command/{uuid}`) lands only while the command
  is queued, which is a second or two: 204, and the status becomes `cancelled`. A command that has started or
  finished is 400 `wrongState` ("Operation not exists"), an unknown one 400 `commandNotExists`. The generic
  endpoint reached the CSV export and the document, shipment creation, file and webhook check commands. The HS code
  command is not known to it, and has no `DELETE` of its own. Aborting an order, a pickup or a
  cancellation was not tried.
- Commands are quick (one to five seconds on the sandbox; adding a package to tracking without a carrier takes
  about five, because Furgonetka works the carrier out).

## Identifiers and types

- `package_id` is Furgonetka's shipment id; `package_no` is the carrier's waybill number, one per parcel of a
  shipment. `GET /packages/{id}` takes either; tracking, label, commands and the bulk operations take the id only
  (a waybill number there is a 404 or a 400).
- Ids are text in requests and numbers in parts of the answers; the library uses strings.
- `service_id` is a contract of the account (`GET /account/services`), not a carrier. One carrier can have
  Furgonetka's contract (`owner: furgonetka`) and one of your own (`owner: client`). A fresh sandbox account has 16
  contracts of Furgonetka's.
- A waybill number made of digits becomes an integer array key in PHP. Read tracking through
  `TrackingResult::numbers()`.
- Naive timestamps (`2021-07-01 12:00:00`) are Warsaw time. `datetime_change` and tracking events carry an offset.
- Empty text is `""`, never `null`, in most answers, and an empty map is `[]`.
- Someone else's shipment is a 400 `packageOwner` on `GET /packages/{id}`, not a 404. A cancelled shipment cannot be
  deleted and leaves a `waiting` copy of itself that can.

## Prices are not errors

`POST /packages/calculate-price` prices several carriers at once. A carrier that cannot take the parcel does not
fail the call: it comes back with `available: false` and its own errors. Read the result per carrier;
`PriceQuoteList::cheapest()` skips the unavailable ones.

## Labels, documents and the protocol

- The file format (PDF, ZPL, EPL) is chosen when the shipment is ordered, or comes from the account settings, and
  cannot be changed. The PDF page size (A4 or A6) can be chosen on every download.
- `GET /packages/{id}/label` answers the file itself. **204** while there is no label yet, and for a package that
  is only tracked (it never has one). A cancelled shipment is a **404** ("Package cancelled", path `/package_id`,
  empty `code`), an unknown one a 404 too. A 410 was never seen.
- A document command answers a **signed** address (`hash`, `expires`, about ten minutes) on `c.furgonetka.pl`. It
  is fetched over https with no token and no company header; the token must not leave the API host. The same
  goes for the CSV export, which is served as `binary/octet-stream`.
- The pickup protocol exists for some carriers (DPD, DHL, InPost Kurier and locker shipments on an own contract,
  FedEx and GLS) and answers 204 where there is none.
- `Accept` for a label may list `application/pdf, text/plain, application/vnd.furgonetka.v1+json`; the server took it.

## Label data ("Inne dane na etykiecie") and returns

- `sender` in a shipment is what is printed on the label; `pickup` is where the courier collects. The library calls
  it `LabelSender`.
- Returns go to the address on the label, not to the pickup address, so a dropshipping shop that puts its own
  name there should know. `warningsFor()` says so.
- It needs no account option. A shipment without it answers `sender: null`. It needs a name or a company
  (`isEmpty` on `/sender/name` and `/sender/company`), and a Polish `county` such as "mazowieckie" is refused
  (`invalidRegion` on `/sender/county`). GLS refuses it altogether ("Service is not supported by this carrier"); the
  documentation's own lists contradict each other, so the API decides.
- Returns and readdressing were never seen succeeding. Every ordered shipment we tried, freshly ordered or in
  any of the simulated tracking states, gets 400 `notPossible` on `/packageId` ("This package cannot be
  returned / readdressed. Please contact customer service"). Some starter shipments answer the return price with a
  500 "Unhandled error". So the successful answers of these methods are built from the specification, and the
  tests say so. They return payment links at once and wait for nothing; a repeated request under the same UUID is
  refused, because the links cannot be read a second time. The shipment's own answer has `return_disposition` and
  `readdressing_disposition` (null on every shipment we read, one in transit included), and the panel's page of a DPD
  shipment in transit offers no return or readdressing either, only cancelling, a pickup and "add similar". The
  `returns_data` some starter shipments carry belongs to the demo shop's return portal, not to these requests.
- The "send to me" link works with and without a token, for an address, a company or a point (a point needs
  `service`). Its data are Base64 in the link.

## Errors

Three shapes:

| Where | Shape |
|-------|-------|
| validation and logic (400, 404, 410, 500) | `{"errors":[{"path","message","details","code"}]}`; `details` is an empty string |
| authorization (401, and a flat 409) | `{"code","message","details"}` |
| the token endpoint | RFC 6749: `{"error","error_description"}`, plus `hint`, and `message` for two-factor |

- Match `code` and `path`, not `message`. `X-Language: en_GB` (the library's default) translates most messages, not
  all (carrier messages and the `/duty` field stay Polish).
- Statuses the documentation does not describe: an unknown command is 400 `commandNotExists`; a wrong number in
  `/tracking` and `/label` is a 404 with an empty `code`; an id made of letters can be a 400 with an empty HTML body.
- `POST /packages/validate` with an empty body answers **200** with the old map-shaped
  `{"status":"ERROR","errors":{...}}`. Only 204 means valid.
- Every JSON answer is an object, never a bare list. Many operations answer 204 with no body.

## Limits

The documentation says 5000 requests per hour per application and 500 per hour for unauthenticated calls. On the
sandbox the limit differs per endpoint with the same token: 5000 on `GET /packages/{id}` and tracking, 500 on
validation, lists, companies, regulations, commands and labels. Every answer carries `X-RateLimit-Limit`,
`X-RateLimit-Remaining` and `X-RateLimit-Retry-After` (0 below the limit); `lastRateLimit()` reads them, and a 429 is
`FurgonetkaApiRateLimitException::retryAfterSeconds()`.

## Schema drift

The published enums do not keep up: the sandbox dictionary lists 30 carriers, the specification's enum ten. So enums
are read with `tryFrom()` and the raw value is kept, and a method that takes a carrier also takes a string.
Changelog items of the last three months: `count` added to the shipment list (25 September), `phone` in answers
deprecated in favour of `phone_number_data` (22 September, and the library reads the new one), `details` removed
from command status, `mpk` and `system_fees` removed, the default Poczta service changed to Pocztex. Deprecated
fields and endpoints are not modelled.

## Sandbox and simulation

- `PUT /packages/tracking/simulate` exists only on the sandbox. It needs **both** `packages_id` and `package_no`
  (a missing one is `isEmpty` on that field), replaces the history with synthetic events ten minutes apart (times
  may lie in the future) and changes the shipment itself: the parcel goes back to `ordered`.
- `ready-for-transport` and `waiting-for-receiver` are refused by the simulation.
- Cancelling works up to the `transit` state and is refused after a delivery state (an `error` result without a
  `code`). A cancelled shipment leaves a `waiting` copy.
- A new sandbox account starts with about 35 ordered shipments, each with a waybill number and an empty history.
- A tracked package cannot be removed, and neither can an uploaded file.
- `GET /packages` returns `count` only for the `waiting` list.

## Tracking

- `fetchTracking()` takes the shipment id and answers per waybill number. `additionalInfoTracking` is an object
  (the specification says an array).
- Every event has a normalised `state` and the carrier's own wording in `status`, in the carrier's language
  whatever `X-Language` says: show it, do not match on it.
- Adding a package: `existsSent` when the number belongs to one of your ordered shipments, `exists` when it is
  tracked already; as a command, an existing number ends as `partial_success` with
  `package_already_exists_in_tracking` on `/package_no` and the id of the package that is there.

## Pickup points

- The identifier is `code`, not the specification's `point_id`.
- The centre of a search by phrase or address comes back as text ("52.2301600000000"), by coordinates as numbers.
  `distance` can be null (the first point of a phrase search).
- A phrase Furgonetka cannot place does not fail: you get the points around a default centre in Warsaw. No
  location at all is a 404 with path `/location`.
- `cod_only`, `food`, `type` and `map_bounds` narrow the result; `digital_label` does not.

## Webhook

- The account has a URL, a token (the "salt" of the checksum) and a format, JSON or XML, and a choice of states.
  **Saving a webhook needs a successful check of that address and format first** (`checkWebhook()`); otherwise 400
  on `/webhookUrl`.
- The receiver has to answer **HTTP 200** with `{"status":"OK"}` (JSON) or `<?xml version="1.0" encoding="UTF-8"?>
  <status>OK</status>` (XML). A plain "OK" ends every check as `error` with an empty error list; an unreachable
  address ends the same way, so the reply of your receiver is the only clue.
- JSON notification: `{"package_id":21351614,"package_no":"...","partner_order_id":"12","tracking":{"datetime":
  "2026-09-29 11:59:34","state":"delivered","description":"...","branch":""},"control":"..."}`; XML has the same
  fields under `<Notification>`. `partner_order_id` is the shipment's `order_number`, and empty in a check.
- Checksum: `md5(package_id . package_no . partner_order_id . state . description . datetime . branch . token)`, the
  values as sent, no separators. Confirmed on a real notification, on the samples of an address check and on the
  example of Furgonetka's documentation. It is not a signature.
- A notification comes **about a minute after the change**, once for the latest state: five simulated changes in a
  row produced one call. Furgonetka waits a few seconds for the answer.
- The log holds only failed deliveries: `{"logs":[{"id","datetimeSend","packageNo","httpCode","trackingState",
  "trackingStateName","timeout","webhookResponse"}],"lastSuccess","isNextPage"}`. A good delivery leaves no entry and
  only moves `lastSuccess`. `webhookResponse` is what your receiver answered (up to 1000 characters). Resending an
  entry (`resendWebhook()`) that then goes through **removes it**; resending it again is 400 `invalid` on `/id`. Paging
  (`lastId` with `lastDatetimeSend`) was not seen: it needs more failed deliveries than a page holds.
- The token is a secret everywhere: it is masked in dumps, traces and logs, and in the request body of a debug
  report.

## Notifications

- Three reads (general options, shipping notifications per tracking state, webhook settings) and one write.
- `POST /account/settings/notification` replaces everything: both lists, the e-mail options and the webhook.
  Leaving `emailBit` out changed the account's general settings on the sandbox; an empty list is 400 `isEmpty`
  (`/orderNotifications/0`, `/trackingNotifications/0`); an unknown state is `notInArray`; an address without a
  format is `isEmpty` on `/webhookFormat`. Build the request from the reads, as
  `NotificationSettingsRequest::from()` does.
- An option the account never set reads `enabled: null` (not false). Saving what was read back changes nothing.
- The freight exchange's push setting (`gieldaPushNotifications`) cannot be read, so it is not carried over.

## Address book

- `PUT /account/address-book/{id}` replaces the entry whole: sending only a company name emptied the name, the
  e-mail, the address and the phone. `AddressBookEntry::toRequest()->with([...])` is the way to change one field.
  The IBAN and its owner's name are never sent back.
- Furgonetka normalises what it stores: "500200300" becomes "+48500200300" (and `phone_number_data`), and the
  city "Krakow" comes back with the accent on the o. An entry of a list has `full_name: null`, a single one `""`.
- A name or a company is required (`isEmpty` on `/name` and `/company`). `POST .../validate` is 204 or the field
  errors.
- Deleting is atomic: one unknown id is 400 `notExists` on `/entries/N/id` and nothing is deleted; an empty list is
  `isEmpty`. A missing entry is 400 `notExists` on `/id`.
- A public entry (`is_public`) has a `send_to_me_link` made from its `uuid`; the uuid survives a replacement only if
  the request carries it. A point entry (`point` and `service`) comes back with the full `point_data`.

## Balance, CSV, HS codes, cart re-check

- Balance dates are days, `YYYY-MM-DD`. The specification's own example, with a time and a zone, is 400
  `dateInvalidDate`. An `items` above 1000 is not refused, it is read as 10. Newest first; `last_id` gives the
  operations below that id. Operation types include `gielda_commission`, which the specification does not list.
- CSV export: `selected` needs shipment ids (an empty entry is `isEmpty` on `/packages/0`), a wrong range is
  `notInArray` on `/range`. The file is a quoted CSV with a header row.
- The cart re-check works only for shipments in the cart (waiting ones). An ordered shipment is a 404 "Parcel is
  not in the cart" on `/packages/id/{id}`, someone else's a 400 `packageOwner`.
- HS codes need the user's token. The specification lists no security for them and the endpoint answered without a
  token on the morning of 2026-09-29, and from early afternoon on it answers 500 without one while the same call with
  a token works. The registry sends the token (`EndpointAuth::User`) and a client without one refuses the call locally.
  `novapost` answers a list (many descriptions per code), `meest` ends as an `error` result (a Polish "no permission
  for this API resource", empty code) on this account, an empty description is accepted.

## Files

- `POST /file-command/{uuid}` is `multipart/form-data` and needs the user's token although the specification lists
  none; a file uploaded without one belongs to nobody and its status is 400 `valueTypeNotValid`.
- An upload answers two UUIDs, the command's (yours) and `file_uuid` (Furgonetka's). **A shipment's `photos` take the
  `file_uuid` of a file uploaded with `type=packagePhoto`**: that is what the panel's own form sends (seen in its
  requests: it uploads with that type, reads the status, and hands the `file_uuid` on). The command's UUID is refused
  like a made-up one (`noFileIncluded` on `/photos`), and so was every UUID of a photo uploaded without that type, which
  is why this took long to find. The panel allows ten photos, the API three. `duty.documents_uuids` accepts any UUID,
  made-up ones included, and having one did not remove the signature the customs documents need (see Shipments
  abroad); `files_uuids` is for Postivo, which this account cannot use. The upload takes any file and any `type`.
- The statuses of several uploads come unordered, and one unknown UUID fails the whole read.

## Shipments abroad

Sending from Poland to another country is the same `ShipmentRequest` with the receiver's `countryCode` and, where
customs apply, `duty`. Checked on the sandbox on 2026-09-29 with the carriers the account has (see the cookbook's
`shipments/*/to-*` recipes):

- Germany and France (DPD, and others) are an ordinary shipment: `fetchAllowedCountries()->requiresDuty()` is false.
  Ireland, the USA and Canada also need a `county`, and a phone number of another country needs its leading plus.
- Great Britain, Switzerland and Norway are countries that need a customs declaration (`duty`: `DutyType`, the goods
  as `ExportItem`s with an HS code). To Great Britain, the library refuses a shipment without it locally and the API
  refuses it too (`isEmpty` on the duty). A sale to Great Britain also needs a real EORI (`invalidEori` on a made-up
  one) and a VAT number, and documents; a sale was not taken further than validation. A gift was created, read and
  deleted.
- A contract does not serve every country. On the sandbox account DPD refuses Great Britain (`notAvailable` on
  `/receiver/country_code`), Poczta Polska refuses Germany (`notAvailable`), UPS refuses the USA (`notAvailable`),
  and DHL takes only some parcel types abroad (`notInArray`). That is a refusal inside the validation and the
  price, never an HTTP error, and another account may be served where this one is not.
- International DPD has a regulation of its own, `dpd_international`, separate from the domestic DPD one.
  Ordering a shipment to Germany before it is accepted is a 409 `terms_and_conditions_not_valid`;
  `pendingRegulations()` names it. After accepting it, a shipment to Germany was ordered, its label fetched and the
  order cancelled.
- **A shipment outside the EU cannot be ordered through the API alone.** On this account only UPS and FedEx serve
  Norway and Switzerland; UPS has its own regulation (`ups`, a 409 until it is accepted). With it accepted, the order
  of a UPS shipment to Norway or Switzerland is a 400 `noSignature` on `/packages/0/id`: "Parcels sent outside the EU
  require a signature on customs documentation. Placing an order with the signature option is only possible in the
  desktop version." Furgonetka generates the customs documents (a UPS exporter's statement and a pro forma invoice)
  and the sender signs them by drawing on a page of the panel (`/konto/potwierdzenie-dokumentow-celnych/{id}`, then
  "add to cart", which saves the signature). The shipment's `duty` then reads `signature_required: false` and
  `documents_signed: true` (`Package::signatureRequired()`, `documentsSigned()`), and the order goes through: Norway and Switzerland were each ordered, labelled and
  cancelled that way. Any change of the shipment (an `updatePackage()`) drops the signature and regenerates the
  documents, and while they are generated an update is a 400 `generate_in_progress` on `/duty/content`. The check
  comes before the balance check, so an unsigned order is refused before any money is looked at.
- Customs documents from the client (`documents_owner: client` with an uploaded file) did not lift the signature
  requirement, and `docs_in_dox` as the duty type is refused for UPS to Norway (`notInArray` on `/duty/type`).
- An order whose price is above the balance is a 400 without a code on `/packages`: "Shipping costs are higher than
  the available balance." A cancellation gives the money back, so a sandbox test that orders and cancels one
  shipment after another needs a balance for one.

## What the cookbook found

Running about two hundred recipes against the sandbox showed a few facts that no single script had touched:

- An extra service the API does not know is ignored, not refused, so a misspelt service name ships without it.
- The list of ordered shipments includes cancelled ones. Pick one for a pickup or tracking by state and waybill
  (`isOrdered()`), not by position.
- ORLEN Paczka points come back with an empty postcode, and the pickup side of a shipment needs one: pass it
  yourself.
- A carrier's rejection at order time could not be provoked. Furgonetka's own validation stops odd data first
  (`cityNotMatchToPostalCode`, `noNumber`, `unavailable`, `notExists`, `invalid`), and what it lets through (letters
  outside the Latin alphabet in a name, markup in a street, a street of a hundred characters) the sandbox carrier took.
- Refusals name their reason as a `code`, which is what to match: `notSupported`, `notAvailable`, `isEmpty`,
  `notInArray`, `sumValueTooHigh`, `pickupCityIsNotInArea`, `packageDimensionalWeightExceeded`,
  `shorterSidesDimensionsExceeded`, `onlyPocztexAvailable`, `invalidEori` and others. The messages are translated and
  change; the codes do not.

## Where the documentation is

| What | Where |
|------|-------|
| OpenAPI specification | `https://furgonetka.pl/api/rest`; the YAML address is the `spec-url` attribute of that page, and its file name carries a hash that changes |
| Sandbox | `https://sandbox.furgonetka.pl/api/rest`, API at `https://api.sandbox.furgonetka.pl` |
| Ordering shipments | `https://furgonetka.pl/api/rest/packages-instruction` |
| OAuth2 | the OAuth page next to it, and the applications panel (`/api/aplikacje-oauth`) |
| Changelog of the API | `https://furgonetka.pl/api/rest/changelog` |
| Webhook and notifications | `https://furgonetka.pl/pomoc/195,jak_dziala_system_powiadomien` |

## Not verified

Return and readdressing success; which UUID a shipment takes for `duty.documents_uuids` and `files_uuids`; a
carrier's rejection inside an order or pickup result (the sandbox never rejected a shipment, so the shape comes from
the documentation); a 410 on a label; two-factor login; paging of the webhook log; aborting an order, a pickup or a
cancellation; ordering abroad other than to Germany, Norway and Switzerland; a sale to Great Britain; the USA (the
account has no carrier for it); ordering and every other call that spends money on production. A read-only pass on
production on 2026-09-29 (public endpoints, `client_credentials`, a password login, services, companies, balance,
shipment list, price and validation of a sample shipment) answered as the sandbox does. The library is written against the documentation for these, and its tests say where a body is built
from the specification and not captured.
