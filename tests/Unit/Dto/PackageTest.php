<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\PackagePage;
use VeryCodeCom\FurgonetkaApi\Dto\PhoneNumber;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DispositionStatus;
use VeryCodeCom\FurgonetkaApi\Enum\PackageState;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelState;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * Parsing of the shipment answers: the four real ones the sandbox gave (created, ordered,
 * cancelled, and a delivered one that came with the account), two to Norway that show the customs
 * signature (`abroad-unsigned`, a fresh one, and `abroad-signed`, one signed in the panel and cancelled
 * later), and two lists.
 */
final class PackageTest extends TestCase
{
    private static function package(string $fixture): Package
    {
        return Package::fromArray(Fixtures::json('packages/' . $fixture));
    }

    public function testACreatedShipment(): void
    {
        $package = self::package('created');

        self::assertSame('21353373', $package->id);
        self::assertNull($package->groupId);
        self::assertSame(PackageState::Waiting, $package->state);
        self::assertSame('waiting', $package->stateValue);
        self::assertSame('dpd', $package->carrierName);
        self::assertSame(Carrier::Dpd, $package->carrier);
        self::assertSame(12014776, $package->serviceId);
        self::assertSame('provided', $package->serviceContract);
        self::assertSame('Test 1', $package->userReferenceNumber);
        self::assertNull($package->transportService, 'Furgonetka sends an empty string here, read as null like every blank string.');
        self::assertFalse($package->cancelAvailable);
        self::assertFalse($package->pickupAvailable);
        self::assertFalse($package->isOrdered());
        self::assertSame('Anna Nowak', $package->name);
        self::assertSame('https://sandbox.furgonetka.pl/konto/edycja-paczki/21353373', $package->editUrl);
        self::assertNull($package->documentsUrl);
        self::assertNull($package->pickupDate);
        self::assertNull($package->datetimeOrder);
        self::assertSame('2026-09-29T07:57:07+02:00', $package->datetimeAdd?->format(\DATE_ATOM));
    }

    public function testTheAddressesAreReadWithThePhoneSplitIntoPrefixAndNumber(): void
    {
        $package = self::package('created');

        $pickup = $package->pickup;
        self::assertNotNull($pickup);
        self::assertSame('Jan Kowalski', $pickup->name);
        self::assertSame('Marszalkowska 100', $pickup->street);
        self::assertSame('00-026', $pickup->postcode);
        self::assertSame('PL', $pickup->countryCode);
        self::assertSame('jan@example.com', $pickup->email);
        self::assertSame('500600700', $pickup->phone?->number);
        self::assertNull($pickup->phone->prefix);
        self::assertSame('500600700', $pickup->phone->international());
        self::assertNull($pickup->point);

        $receiver = $package->receiver;
        self::assertNotNull($receiver);
        self::assertSame('Kraków', $receiver->city, 'Furgonetka normalised "Krakow".');
        self::assertNull($receiver->uuid);
        self::assertNull($package->labelSender, 'No other data on the label.');
    }

    public function testThePricingOfAShipment(): void
    {
        $pricing = self::package('created')->pricing;

        self::assertNotNull($pricing);
        self::assertSame(21.41, $pricing->priceGross);
        self::assertSame(17.41, $pricing->priceNet);
        self::assertSame(12.14, $pricing->priceBaseNet);
        self::assertSame(23, $pricing->tax);
        self::assertNull($pricing->priceInfo, 'An empty string in the answer.');
        self::assertNull($pricing->additionalServicesPriceNet);
        self::assertSame(['fuel_surcharge', 'road_tolls', 'energy_fee'], array_map(static fn ($d): ?string => $d->service, $pricing->details));
        self::assertSame(4.46, $pricing->details[0]->priceNet);
        self::assertSame('Opłata paliwowa', $pricing->details[0]->description);
    }

    public function testTheParcelOfAShipmentThatWasNotOrderedHasNoWaybill(): void
    {
        $package = self::package('created');

        self::assertCount(1, $package->parcels);
        $parcel = $package->parcels[0];
        self::assertNull($parcel->packageNo);
        self::assertSame(ParcelState::Waiting, $parcel->state);
        self::assertSame(ParcelType::Package, $parcel->type);
        self::assertSame(30, $parcel->width);
        self::assertSame(40, $parcel->depth);
        self::assertSame(20, $parcel->height);
        self::assertSame(5.0, $parcel->weight);
        self::assertSame(100.0, $parcel->value);
        self::assertSame('Ksiazki', $parcel->description);
        self::assertSame([], $package->waybillNumbers());
    }

    public function testAnOrderedShipmentHasAWaybillAndAPickupThatCanBeBooked(): void
    {
        $package = self::package('ordered');

        self::assertTrue($package->isOrdered());
        self::assertSame(PackageState::Ordered, $package->state);
        self::assertTrue($package->pickupAvailable);
        self::assertTrue($package->cancelAvailable);
        self::assertSame(['0000888261798Q'], $package->waybillNumbers());
        self::assertSame("ordered", $package->parcels[0]->stateValue);
    }

    public function testACancelledShipment(): void
    {
        $package = self::package('cancelled');

        self::assertSame(PackageState::Cancelled, $package->state);
        self::assertFalse($package->cancelAvailable);
        self::assertFalse($package->isOrdered());
    }

    public function testADeliveredParcelOfAShipmentThatIsStillOrdered(): void
    {
        $package = self::package('delivered');

        self::assertSame('21351614', $package->id);
        self::assertSame(PackageState::Ordered, $package->state);
        self::assertSame(ParcelState::Delivered, $package->parcels[0]->state);
        self::assertSame('1582893168850', $package->parcels[0]->packageNo);
        self::assertSame('Doręczona', $package->parcels[0]->stateDescription);
        self::assertSame(10.0, $package->parcels[0]->dimensionalWeight);
        self::assertSame('https://sandbox.furgonetka.pl/zlokalizuj/1582893168850/dpd', $package->parcels[0]->trackingUrl);
        self::assertSame('700123456', $package->receiver?->phone?->number);
    }

    public function testTheWholeObjectIsAvailableForFieldsThatAreNotModelled(): void
    {
        $package = self::package('ordered');

        self::assertArrayHasKey('additional_services_dictionary', $package->raw());
        self::assertArrayHasKey('changes', $package->raw());
        self::assertSame(true, $package->additionalServices['insurance']);
        self::assertGreaterThan(50, count($package->additionalServices));
        self::assertSame('0000888261798Q', $package->parcels[0]->raw()['package_no']);
    }

    public function testAShipmentThatLeavesTheEuTellsWhetherItsCustomsDocumentsAreSigned(): void
    {
        $unsigned = self::package('abroad-unsigned');
        $signed = self::package('abroad-signed');

        self::assertSame(PackageState::Waiting, $unsigned->state);
        self::assertTrue($unsigned->signatureRequired(), 'An order would be a 400 noSignature.');
        self::assertFalse($unsigned->documentsSigned());

        self::assertSame(PackageState::Cancelled, $signed->state);
        self::assertFalse($signed->signatureRequired(), 'Signing takes the requirement away.');
        self::assertTrue($signed->documentsSigned());
    }

    public function testAShipmentWithoutCustomsDataHasNothingToSign(): void
    {
        foreach (['created', 'ordered', 'cancelled', 'delivered'] as $fixture) {
            $package = self::package($fixture);

            self::assertNull($package->signatureRequired(), $fixture);
            self::assertNull($package->documentsSigned(), $fixture);
        }
    }

    public function testNoShipmentTheSandboxGaveHasAReturnOrAReaddressingOrdered(): void
    {
        foreach (['created', 'ordered', 'cancelled', 'delivered', 'abroad-signed'] as $fixture) {
            $package = self::package($fixture);

            self::assertNull($package->returnDisposition(), $fixture);
            self::assertNull($package->readdressingDisposition(), $fixture);
        }
    }

    public function testAnEmptyDispositionIsTakenForNone(): void
    {
        // The PHP backend writes an empty object as [].
        $data = Fixtures::json('packages/ordered');
        $data['return_disposition'] = [];
        $data['readdressing_disposition'] = [];
        $package = Package::fromArray($data);

        self::assertNull($package->returnDisposition());
        self::assertNull($package->readdressingDisposition());
    }

    public function testADispositionIsReadWhenThereIsOne(): void
    {
        // Shapes from the specification (ReturnDisposition, ReaddressingDisposition), not captured bodies: the
        // sandbox never let a return or a readdressing through.
        $data = Fixtures::json('packages/ordered');
        $data['return_disposition'] = ['status' => 'paid', 'datetime' => '2026-09-29T12:00:00+02:00'];
        $data['readdressing_disposition'] = ['status' => 'new', 'datetime' => '2026-09-29T13:00:00+02:00', 'name' => 'Zofia Wisniewska', 'city' => 'Krakow', 'country_code' => 'PL', 'phone' => '600700800'];
        $package = Package::fromArray($data);

        self::assertSame(DispositionStatus::Paid, $package->returnDisposition()?->status);
        self::assertSame(DispositionStatus::New, $package->readdressingDisposition()?->status);
        self::assertSame('Zofia Wisniewska', $package->readdressingDisposition()->name);
    }

    public function testAnUnknownStateAndCarrierReadAsNullAndKeepTheRawValue(): void
    {
        $data = Fixtures::json('packages/created');
        $data['state'] = 'lost-in-space';
        $data['service'] = 'furgonetka_gielda';
        $data['parcels'][0]['state'] = 'teleporting';
        $data['parcels'][0]['type'] = 'spaceship';

        $package = Package::fromArray($data);

        self::assertNull($package->state);
        self::assertSame('lost-in-space', $package->stateValue);
        self::assertNull($package->carrier);
        self::assertSame('furgonetka_gielda', $package->carrierName);
        self::assertNull($package->parcels[0]->state);
        self::assertSame('teleporting', $package->parcels[0]->stateValue);
        self::assertNull($package->parcels[0]->type);
        self::assertSame('spaceship', $package->parcels[0]->typeValue);
    }

    public function testAnIdThatComesAsANumberIsAString(): void
    {
        $package = Package::fromArray(['package_id' => 90014858123, 'service_id' => '12014776']);

        self::assertSame('90014858123', $package->id);
        self::assertSame(12014776, $package->serviceId);
    }

    public function testAnEmptyObjectIsAllNulls(): void
    {
        $package = Package::fromArray([]);

        self::assertNull($package->id);
        self::assertNull($package->pickup);
        self::assertNull($package->pricing);
        self::assertSame([], $package->parcels);
        self::assertSame([], $package->waybillNumbers());
        self::assertSame([], $package->raw());
    }

    public function testALabelSenderAndABookedPickupAreRead(): void
    {
        $data = Fixtures::json('packages/created');
        $data['sender'] = ['name' => 'Piotr Zielinski', 'street' => 'Prosta 1', 'postcode' => '00-838', 'city' => 'Warszawa', 'phone_number_data' => ['prefix' => '48', 'phone_number' => '500100200', 'country_code' => 'PL'], 'only_pickup_provided' => false];
        $data['pickup_date'] = ['date' => '2026-09-30', 'min_time' => '09:00', 'max_time' => '17:00', 'hash' => 'e9942106485efca3947053b3a503c33d', 'info' => null];

        $package = Package::fromArray($data);

        self::assertSame('Piotr Zielinski', $package->labelSender?->name);
        self::assertFalse($package->labelSender->onlyPickupProvided);
        self::assertSame('+48500100200', $package->labelSender->phone?->international());
        self::assertSame('PL', $package->labelSender->phone->countryCode);
        self::assertSame('2026-09-30', $package->pickupDate?->date);
        self::assertSame('09:00', $package->pickupDate->minTime);
        self::assertNull($package->pickupDate->available, 'A booked pickup has no availability.');
    }

    public function testAPhoneWithoutANumberHasNoInternationalForm(): void
    {
        self::assertNull(PhoneNumber::fromArray(['prefix' => '48'])->international());
        self::assertSame('+48500100200', PhoneNumber::fromArray(['prefix' => '48', 'phone_number' => '500100200'])->international());
    }

    // -- Lists ------------------------------------------------------------------------------

    public function testTheWaitingListHasACount(): void
    {
        $page = PackagePage::fromArray(Fixtures::json('packages/list-waiting'));

        self::assertSame(2, $page->count);
        self::assertCount(2, $page);
        self::assertSame(['21353478', '21353475'], array_map(static fn (Package $p): ?string => $p->id, $page->packages));
        self::assertSame(PackageState::Waiting, $page->packages[0]->state);
    }

    public function testAnyOtherListHasNoCount(): void
    {
        $page = PackagePage::fromArray(Fixtures::json('packages/list-page'));

        self::assertNull($page->count, 'Furgonetka sends the count for the waiting list only.');
        self::assertCount(2, $page);
    }

    public function testThePageIsIterableAndKnowsWhereTheNextOneStarts(): void
    {
        $page = PackagePage::fromArray(Fixtures::json('packages/list-waiting'));

        $ids = [];
        foreach ($page as $package) {
            $ids[] = $package->id;
        }

        self::assertSame(['21353478', '21353475'], $ids);
        self::assertSame('21353475', $page->nextLastPackageId(), 'The next page starts after the last id of this one.');
        self::assertArrayHasKey('packages', $page->raw());
    }

    public function testAnEmptyPageHasNoNext(): void
    {
        $page = PackagePage::fromArray(['packages' => [], 'count' => 0]);

        self::assertCount(0, $page);
        self::assertNull($page->nextLastPackageId());
        self::assertSame(0, $page->count);
    }
}
