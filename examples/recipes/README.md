# Cookbook

About two hundred small scenarios, one file each: one carrier and one thing that is special about it, or one query.
Where the numbered scripts in [examples](..) show a flow from beginning to end, the recipes show the pieces, so that
you can find the one you need and copy it.

There are two kinds:

- **Shipment recipes** (`shipments/`) build a `ShipmentRequest` for one carrier: a service, a parcel type, a country,
  a point, a customs declaration. Running one validates it (locally and with Furgonetka) and prices it. Nothing is
  ever created or ordered.
- **Query recipes** (`points/`, `lists/`, `account/`, `prices/`) make read-only calls and print what they found.

About a third of the shipment recipes show a **refusal**: a service a carrier does not have, a limit, a country a
contract does not serve. They are as useful as the accepted ones, because the refusal (its error code and the field
it names) is what your code will meet, and the recipe names it.

Every recipe was run against the sandbox, and `tests/Integration/RecipesTest.php` runs them all again with every
integration run, so a recipe that stops behaving as its file says (Furgonetka changed a rule, or the account did) is
found and not left to rot. The contracts of the sandbox account decide some outcomes: another account may be refused
where this one is accepted, and a recipe for a carrier the account has no contract with is skipped.

## Running them

Sign in first (see [Getting started](../../docs/GETTING-STARTED.md)), on the sandbox:

```bash
php examples/recipes/run.php                         # the list
php examples/recipes/run.php shipments/dpd/cod       # one recipe, in full: the body that goes on the wire, the result
php examples/recipes/run.php shipments/dpd/          # every recipe whose name starts so, one line each
php examples/recipes/run.php all                     # everything (about 400 requests: mind the hourly limit of 500 per endpoint)
```

A shipment recipe printed in full shows the JSON body of the request, the local errors and warnings, Furgonetka's
verdict and the price. The exit code is 1 when a recipe does not behave as its file says.

## How a recipe is written

A recipe file returns a `Recipe`. A shipment recipe gets a `Scenario`, which gives a working domestic shipment
(Warsaw to Krakow, one 5 kg parcel, on the account's contract with the carrier) to change in the one respect the
recipe is about:

```php
return Recipe::shipment(
    title: 'DPD: cash on delivery',
    shows: 'Cod carries the amount and the account the carrier pays it out to ...',
    carrier: Carrier::Dpd,
    request: static fn (Scenario $s): ShipmentRequest => $s->request(
        services: new AdditionalServices(cod: new Cod(100.0, iban: 'PL61109010140000071219812874', name: 'Jan Kowalski')),
    ),
    refusedWith: [],            // or the error codes of a recipe that shows a refusal
);
```

`Scenario::request()` takes `pickup`, `receiver`, `parcels`, `services`, `labelSender`, `duty`, `payer` and
`reference`. Helpers find a real pickup point (`pointReceiver()`, `pointPickup()`) the way an application would, by
asking Furgonetka, so no recipe depends on a point code that may go away. The addresses, phone numbers and the IBAN
are made up: replace them before you copy a recipe into a real integration.

To add one, put a file under the right folder, list it in the tables below, and run it with `run.php`.

## The recipes

### Shipments, by carrier

#### DPD (46)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/dpd/basic` | DPD: the plainest shipment | accepted, priced |
| `shipments/dpd/two-parcels` | DPD: two parcels of different sizes | accepted, priced |
| `shipments/dpd/fifteen-parcels` | DPD: fifteen parcels, the most a shipment can hold | accepted, priced |
| `shipments/dpd/pallet` | DPD: a pallet | accepted, priced |
| `shipments/dpd/heavy-parcel` | DPD: a parcel of 31.5 kg | accepted, priced |
| `shipments/dpd/envelope` | DPD: an envelope | accepted, priced |
| `shipments/dpd/envelope-without-dimensions-refused` | DPD: an envelope without dimensions is refused | refused: `isEmpty` |
| `shipments/dpd/oversize-refused` | DPD: a parcel that is too long is refused | refused: `packageDimensionalWeightExceeded`, `shorterSidesDimensionsExceeded` |
| `shipments/dpd/other-parcel-type-refused` | DPD: parcel types the carrier does not take | refused: `notInArray` |
| `shipments/dpd/company-receiver` | DPD: a company as the receiver, with a contact person | accepted, priced |
| `shipments/dpd/company-only-receiver` | DPD: a company without a person | accepted, priced |
| `shipments/dpd/receiver-without-email` | DPD: a receiver without an e-mail address | accepted, priced |
| `shipments/dpd/phone-with-prefix` | DPD: a phone number with the country prefix | accepted, priced |
| `shipments/dpd/street-with-flat-number` | DPD: building and flat number in the street | accepted, priced |
| `shipments/dpd/reference-number` | DPD: your own reference number | accepted, priced |
| `shipments/dpd/cost-centre` | DPD: a cost centre | accepted, priced |
| `shipments/dpd/label-sender` | DPD: other data on the label | accepted, priced |
| `shipments/dpd/cod` | DPD: cash on delivery | accepted, priced |
| `shipments/dpd/cod-without-account-owner-refused` | DPD: cash on delivery without the account owner is refused | refused: `isEmpty` |
| `shipments/dpd/cod-above-value-refused` | DPD: cash on delivery above the value is refused | refused: `invalidValueWithoutInsurance` |
| `shipments/dpd/cod-in-euro` | DPD: cash on delivery in euro | accepted, priced |
| `shipments/dpd/cod-express-refused` | DPD: express cash on delivery is refused | refused: `invalid` |
| `shipments/dpd/cod-and-guarantee` | DPD: cash on delivery with a delivery time guarantee | accepted, priced |
| `shipments/dpd/cod-and-insured` | DPD: cash on delivery of a valuable parcel | accepted, priced |
| `shipments/dpd/return-document` | DPD: a return document (ROD) | accepted, priced |
| `shipments/dpd/return-document-and-private` | DPD: a return document to a private person | accepted, priced |
| `shipments/dpd/return-document-and-cud-refused` | DPD: a return document together with CUD is refused | refused: `disableForTwo`, `notSupported` |
| `shipments/dpd/cud-refused` | DPD: CUD is not supported | refused: `notSupported` |
| `shipments/dpd/private-delivery` | DPD: delivery to a private person | accepted, priced |
| `shipments/dpd/guaranteed-1200` | DPD: delivery guaranteed by 12:00 | accepted, priced |
| `shipments/dpd/guaranteed-next-day` | DPD: guaranteed next-day delivery | accepted, priced |
| `shipments/dpd/guaranteed-0930-refused` | DPD: delivery by 9:30 is not available | refused: `notAvailable9300` |
| `shipments/dpd/saturday-delivery` | DPD: Saturday delivery | accepted, priced |
| `shipments/dpd/saturday-and-next-day-refused` | DPD: Saturday delivery with a next-day guarantee is refused | refused: `noAll` |
| `shipments/dpd/receiver-email-notification` | DPD: an e-mail to the receiver | accepted, priced |
| `shipments/dpd/sms-notification-refused` | DPD: an SMS to the receiver is not supported | refused: `notSupported` |
| `shipments/dpd/delivery-on-day-refused` | DPD: delivery on a chosen day is not supported | refused: `notSupported` |
| `shipments/dpd/fragile-refused` | DPD: a fragile flag is not supported | refused: `notSupported` |
| `shipments/dpd/valuable-refused` | DPD: a valuable shipment flag is not supported | refused: `notSupported` |
| `shipments/dpd/digital-label-refused` | DPD: a digital label is not supported | refused: `notSupported` |
| `shipments/dpd/unknown-service-is-ignored` | DPD: a misspelt service is silently ignored | accepted, priced |
| `shipments/dpd/to-germany` | DPD: to Germany | accepted, priced |
| `shipments/dpd/to-france` | DPD: to France | accepted, priced |
| `shipments/dpd/to-united-kingdom-refused` | DPD: to the United Kingdom is not served by this contract | refused: `notAvailable` |
| `shipments/dpd/to-pickup-point` | DPD: to a Pickup point | accepted, priced |
| `shipments/dpd/from-pickup-point` | DPD: dropped off at a Pickup point | accepted, priced |

#### InPost (16)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/inpost/courier` | InPost: a courier shipment to an address | accepted, priced |
| `shipments/inpost/locker` | InPost: to a parcel locker | accepted, priced |
| `shipments/inpost/locker-cash-on-delivery` | InPost: cash on delivery to a parcel locker | accepted, priced |
| `shipments/inpost/courier-cash-on-delivery` | InPost: cash on delivery by courier | accepted, priced |
| `shipments/inpost/sender-at-locker` | InPost: dropped off at a parcel locker | accepted, priced |
| `shipments/inpost/pop-point` | InPost: to a PaczkoPunkt | accepted, priced |
| `shipments/inpost/mini-or-standard` | InPost: the mini or standard size | accepted, priced |
| `shipments/inpost/two-parcels` | InPost: two parcels in one shipment | accepted, priced |
| `shipments/inpost/courier-to-company` | InPost: a courier delivery to a company | accepted, priced |
| `shipments/inpost/receiver-email-notification` | InPost: an e-mail to the receiver | accepted, priced |
| `shipments/inpost/big-parcel` | InPost: a heavier courier parcel | accepted, priced |
| `shipments/inpost/locker-oversize-refused` | InPost: a parcel too big for a locker is refused | refused: `packageDimensionExceededDomestic` |
| `shipments/inpost/any-apm-refused` | InPost: "any locker" is not a point on this account | refused: `invalid` |
| `shipments/inpost/saturday-refused` | InPost: Saturday delivery is not available | refused: `notAvailable` |
| `shipments/inpost/digital-label-refused` | InPost: a digital label is not supported | refused: `notSupported` |
| `shipments/inpost/label-sender` | InPost: other data on the label | accepted, priced |

#### GLS (7)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/gls/basic` | GLS: a domestic shipment | accepted, priced |
| `shipments/gls/two-parcels` | GLS: two parcels | accepted, priced |
| `shipments/gls/to-germany` | GLS: to Germany | accepted, priced |
| `shipments/gls/to-france` | GLS: to France | accepted, priced |
| `shipments/gls/cash-on-delivery-refused` | GLS: cash on delivery is not supported | refused: `notSupported` |
| `shipments/gls/label-sender-refused` | GLS: other data on the label is refused | refused: `name` |
| `shipments/gls/fifteen-parcels-refused` | GLS: fifteen parcels worth 100 PLN each are too much | refused: `sumValueTooHigh` |

#### FedEx (8)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/fedex/basic` | FedEx: a domestic shipment | accepted, priced |
| `shipments/fedex/two-parcels` | FedEx: two parcels | accepted, priced |
| `shipments/fedex/cash-on-delivery` | FedEx: cash on delivery | accepted, priced |
| `shipments/fedex/insured` | FedEx: a valuable parcel | accepted, priced |
| `shipments/fedex/to-point` | FedEx: to a FedEx point | accepted, priced |
| `shipments/fedex/to-france` | FedEx: to France | accepted, priced |
| `shipments/fedex/label-sender` | FedEx: other data on the label | accepted, priced |
| `shipments/fedex/priority-refused` | FedEx: the Priority service is not supported | refused: `notSupported` |

#### UPS (16)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/ups/basic` | UPS: a domestic shipment | accepted, priced |
| `shipments/ups/saver` | UPS: the Saver service | accepted, priced |
| `shipments/ups/cash-on-delivery` | UPS: cash on delivery | accepted, priced |
| `shipments/ups/heavy-parcel` | UPS: a parcel of 30 kg | accepted, priced |
| `shipments/ups/two-parcels-refused` | UPS: two parcels in one shipment are refused | refused: `notLessThanInclusive` |
| `shipments/ups/access-point` | UPS: to a UPS Access Point | accepted, priced |
| `shipments/ups/label-sender` | UPS: other data on the label | accepted, priced |
| `shipments/ups/notifications-refused` | UPS: the UPS notification flags are not supported | refused: `notSupported` |
| `shipments/ups/to-germany` | UPS: to Germany | accepted, priced |
| `shipments/ups/to-ireland` | UPS: to Ireland, with a county | accepted, priced |
| `shipments/ups/to-norway` | UPS: to Norway, with customs data | accepted, priced |
| `shipments/ups/to-switzerland` | UPS: to Switzerland, with customs data | accepted, priced |
| `shipments/ups/to-united-kingdom-gift` | UPS: a gift to the United Kingdom | accepted, priced |
| `shipments/ups/to-united-kingdom-without-duty-refused` | UPS: to the United Kingdom without customs data is refused | refused: `isEmpty` |
| `shipments/ups/to-united-kingdom-sale-refused` | UPS: a sale to the United Kingdom needs a real EORI and documents | refused: `invalidEori` |
| `shipments/ups/to-united-states-not-available` | UPS: the United States are not served by this contract | refused: `notAvailable` |

#### DHL (11)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/dhl/basic` | DHL: a domestic shipment | accepted, priced |
| `shipments/dhl/parcelshop` | DHL: to a ParcelShop | accepted, priced |
| `shipments/dhl/cash-on-delivery` | DHL: cash on delivery | accepted, priced |
| `shipments/dhl/pallet` | DHL: a pallet | accepted, priced |
| `shipments/dhl/five-pallets` | DHL: five pallets, the most a shipment can hold | accepted, priced |
| `shipments/dhl/fifteen-parcels` | DHL: fifteen parcels | accepted, priced |
| `shipments/dhl/envelope` | DHL: an envelope | accepted, priced |
| `shipments/dhl/label-sender` | DHL: other data on the label | accepted, priced |
| `shipments/dhl/saturday-refused` | DHL: Saturday delivery is not available | refused: `notAvailable` |
| `shipments/dhl/delivery-on-time-refused` | DHL: delivery at a chosen time is not supported | refused: `notSupported` |
| `shipments/dhl/to-germany-with-a-package-refused` | DHL: to Germany, as this parcel type | refused: `notInArray` |

#### Poczta Polska (9)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/poczta/basic` | Poczta Polska: a domestic shipment | accepted, priced |
| `shipments/poczta/pocztex` | Poczta Polska: Pocztex | accepted, priced |
| `shipments/poczta/cash-on-delivery` | Poczta Polska: cash on delivery | accepted, priced |
| `shipments/poczta/to-point` | Poczta Polska: to a point | accepted, priced |
| `shipments/poczta/ten-parcels` | Poczta Polska: ten parcels | accepted, priced |
| `shipments/poczta/kurier48-refused` | Poczta Polska: Kurier48 needs a point on the sender | refused: `onlyPocztexAvailable` |
| `shipments/poczta/ekspres24-refused` | Poczta Polska: Ekspres24 is not supported | refused: `notSupported` |
| `shipments/poczta/label-sender-refused` | Poczta Polska: other data on the label is refused | refused: `name` |
| `shipments/poczta/to-germany-refused` | Poczta Polska: to Germany is not served here | refused: `notAvailable` |

#### ORLEN Paczka (2)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/orlen/point-to-point` | ORLEN Paczka: from a point to a point | accepted, priced |
| `shipments/orlen/without-points-refused` | ORLEN Paczka: an address delivery is refused | refused: `isEmpty` |

#### AmbroExpress (4)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/ambroexpress/basic` | AmbroExpress: a domestic shipment | accepted, priced |
| `shipments/ambroexpress/size-20` | AmbroExpress: the 20 size | accepted, priced |
| `shipments/ambroexpress/cash-on-delivery` | AmbroExpress: cash on delivery | accepted, priced |
| `shipments/ambroexpress/two-parcels` | AmbroExpress: two parcels | accepted, priced |

#### Meest (1)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/meest/domestic-refused` | Meest: a domestic shipment is refused | refused: `wrong` |

#### Nova Post (1)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/novapost/domestic-refused` | Nova Post: a domestic shipment is refused | refused: `wrong` |

#### Deligoo (1)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/deligoo/outside-service-area-refused` | Deligoo: an address outside the service area is refused | refused: `pickupCityIsNotInArea` |

#### Xpress Delivery (1)

| Recipe | What it shows | Outcome |
|--------|---------------|---------|
| `shipments/xpressdelivery/offer-ended-refused` | Xpress Delivery: an offer that has ended | refused: `xpressDeliveryUnavailable` |

### Queries

#### Pickup points (20)

| Recipe | What it shows |
|--------|---------------|
| `points/inpost-near-an-address` | Points: InPost near an address given as a phrase |
| `points/by-coordinates` | Points: around a pair of coordinates |
| `points/by-address` | Points: around a structured address |
| `points/several-carriers-at-once` | Points: several carriers in one search |
| `points/cash-on-delivery-only` | Points: only those that take cash on delivery |
| `points/parcel-lockers-only` | Points: only parcel lockers |
| `points/staffed-points-only` | Points: only staffed points |
| `points/drop-off-points` | Points: where the sender can leave a parcel |
| `points/within-half-a-kilometre` | Points: within a distance |
| `points/in-a-map-box` | Points: inside the visible part of a map |
| `points/one-point-by-code` | Points: one point by its code |
| `points/opening-hours` | Points: opening hours |
| `points/orlen` | Points: ORLEN Paczka |
| `points/dpd-pickup` | Points: DPD Pickup |
| `points/poczta` | Points: Poczta Polska |
| `points/ups-access-points` | Points: UPS Access Points |
| `points/dhl-parcelshops` | Points: DHL ParcelShops |
| `points/fedex` | Points: FedEx |
| `points/furgonetka-points` | Points: Furgonetka Punkt |
| `points/a-phrase-it-cannot-place` | Points: a phrase that cannot be placed does not fail |

#### Lists of shipments (19)

| Recipe | What it shows |
|--------|---------------|
| `lists/ordered-shipments` | Shipments: the ordered ones |
| `lists/waiting-shipments` | Shipments: the waiting ones |
| `lists/sent-shipments` | Shipments: the sent ones |
| `lists/tracked-packages` | Shipments: packages you only track |
| `lists/all-shipments` | Shipments: everything |
| `lists/only-dpd` | Shipments: one carrier |
| `lists/several-carriers` | Shipments: several carriers |
| `lists/domestic-only` | Shipments: only domestic ones |
| `lists/international-only` | Shipments: only international ones |
| `lists/with-cash-on-delivery` | Shipments: only those with cash on delivery |
| `lists/since-the-first-of-the-month` | Shipments: since the first of this month |
| `lists/an-interval-shortcut` | Shipments: an interval shortcut |
| `lists/only-envelopes` | Shipments: only envelopes |
| `lists/on-furgonetkas-contracts` | Shipments: on Furgonetka's own contracts |
| `lists/two-pages` | Shipments: paging |
| `lists/by-id` | Shipments: by id |
| `lists/text-search` | Shipments: a search by text |
| `lists/count-by-carrier` | Shipments: how many per carrier |
| `lists/count-by-state` | Shipments: how many in each state |

#### The account (22)

| Recipe | What it shows |
|--------|---------------|
| `account/carrier-contracts` | Account: the carrier contracts |
| `account/carriers-in-the-dictionary` | Account: the carriers of the dictionary |
| `account/countries-and-customs` | Account: countries and which ones need customs data |
| `account/regulations-summary` | Account: the regulations and consents |
| `account/companies` | Account: the companies of the user |
| `account/rate-limit` | Account: the request quota |
| `account/address-book` | Account: the address book |
| `account/address-book-search` | Account: searching the address book |
| `account/notification-options` | Account: the e-mail notification options |
| `account/notification-states` | Account: what is sent at each tracking state |
| `account/webhook-settings` | Account: the webhook |
| `account/webhook-log` | Account: the webhook log |
| `account/balance-latest` | Account: the latest operations |
| `account/balance-spent-this-month` | Account: what was spent this month |
| `account/balance-received` | Account: what came in |
| `account/balance-by-group` | Account: only one group of operations |
| `account/balance-paging` | Account: paging through the operations |
| `account/own-contracts-and-pricelist-changes` | Account: your own contracts and announced price list changes |
| `account/hs-codes-for-novapost` | Account: customs tariff codes for Nova Post |
| `account/hs-codes-without-permission` | Account: customs tariff codes for a carrier the account may not use |
| `account/pickup-proposals` | Account: when a courier could come |
| `account/tracking-of-the-latest-shipment` | Account: the tracking of the newest shipment |

#### Prices (9)

| Recipe | What it shows |
|--------|---------------|
| `prices/all-carriers` | Prices: every carrier for one shipment |
| `prices/cash-on-delivery-surcharge` | Prices: what cash on delivery adds |
| `prices/by-weight` | Prices: how the weight changes the price |
| `prices/by-size` | Prices: how the size changes the price |
| `prices/by-service-id` | Prices: by contract instead of by carrier |
| `prices/to-germany` | Prices: a parcel to Germany |
| `prices/who-cannot-take-it` | Prices: why a carrier cannot take a shipment |
| `prices/insurance-by-value` | Prices: what a higher declared value costs |
| `prices/delivery-guarantees` | Prices: what a delivery guarantee costs |
