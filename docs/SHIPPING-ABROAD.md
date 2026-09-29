# Sending abroad

A shipment to another country is the same `ShipmentRequest` with the receiver's `countryCode`. Countries that need
customs data (on the sandbox Great Britain, Switzerland and Norway; `fetchAllowedCountries()->requiresDuty('GB')`
asks) also take a `Duty`:

```php
use VeryCodeCom\FurgonetkaApi\Dto\{Duty, ExportItem, Parcel, Receiver, ShipmentRequest};
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;

$book = new ExportItem(
    nameMaterial: 'Printed paperback books', itemComposition: 'Paper and card board', itemPurpose: 'Reading by a private person',
    itemsCount: 1, itemPrice: 100.0, countryCode: 'PL', invoiceNo: 'FV/1/2026', itemWeight: 5.0, tariff: '4901990000',
);

$request = new ShipmentRequest(
    serviceId: $ups->id,
    pickup: $pickup,
    receiver: new Receiver(name: 'Ola Nordmann', street: 'Karl Johans gate 1', postcode: '0154', city: 'Oslo', countryCode: 'NO', email: 'ola@example.com', phone: '+4791234567'),
    parcels: [Parcel::package(30, 40, 20, 5.0, 100.0, 'Books')],
    duty: new Duty([$book], DutyType::Gift),
);
```

Germany, France and the rest of the EU need nothing extra. Everything below was checked on the sandbox on
2026-09-29; nothing here was run against production.

## Countries and contracts

A carrier serves some countries and your contract fewer. A country it does not serve comes back as a refusal in the
validation and the price, never as an HTTP error, so ask `calculatePrices()` before you offer a destination. On the
sandbox account DPD refuses Great Britain (`notAvailable` on `/receiver/country_code`), Poczta Polska refuses Germany,
UPS refuses the USA, and DHL takes only some parcel types abroad (`notInArray`). Another account may be served where
this one is not. Only UPS and FedEx served Norway and Switzerland.

## What the local rules refuse

The rules refuse what Furgonetka refuses: customs data for a country that needs it (Great Britain refuses a shipment
without it, `isEmpty` on `/duty`), a `county` for Ireland, the USA and Canada, and an EORI for a sale. A phone number of
another country needs its leading plus. A sale to Great Britain also needs a real EORI (`invalidEori` on a made-up one),
a VAT number and documents, and was not taken further than validation.

## Regulations

International DPD has a regulation of its own, `dpd_international`, separate from the domestic one. Ordering a
shipment to Germany before it is accepted is a 409, and `pendingRegulations()` names it. After accepting it, DPD to
Germany was ordered, labelled and cancelled. UPS has its own regulation as well.

## Outside the EU: the customs signature

An order for a shipment to Norway or Switzerland (Great Britain was not tried) is refused with a 400 `noSignature`
until the sender has signed the customs documents on Furgonetka's own page:

```
Parcels sent outside the EU require a signature on customs documentation. Placing an order with the signature
option is only possible in the desktop version.   [noSignature] (/packages/0/id)
```

Furgonetka generates the documents (for UPS an exporter's statement and a pro forma invoice) and the sender draws a
signature under them on `https://sandbox.furgonetka.pl/konto/potwierdzenie-dokumentow-celnych/{package id}`, then
saves it with "add to cart". The API has no way to give a signature. The shipment tells where it stands:

```php
$package = $client->fetchPackage($package);

if ($package->signatureRequired() === true) {
    // Send the sender to the page above; call orderPackages() once documentsSigned() is true.
}
```

Both methods are null for a shipment without customs data. After signing, `signatureRequired()` is `false` and `documentsSigned()` is `true`, and the order goes through: Norway and
Switzerland were each ordered, labelled and cancelled that way. Changing the shipment with `updatePackage()` drops the
signature and regenerates the documents (an update while they are generated is a 400 `generate_in_progress`), so sign
last. The check comes before the balance check, so an unsigned order is refused before any money is looked at.
Documents supplied by the client (`documentsOwner: Client` with an uploaded file) did not lift the requirement.

## Balance

An order whose price is above the balance is a 400 without a code on `/packages`: "Shipping costs are higher than the
available balance." A cancellation gives the money back.

## More

The [cookbook](../examples/recipes) has a recipe for shipments to Germany, France, Ireland, Norway and Great Britain,
with a gift, a sale and the refusals. [FURGONETKA-NOTES.md](FURGONETKA-NOTES.md) has the same findings among the other
quirks of the API.
