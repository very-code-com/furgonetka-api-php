<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\AllowedCountries;
use VeryCodeCom\FurgonetkaApi\Dto\Company;
use VeryCodeCom\FurgonetkaApi\Dto\Dictionary;
use VeryCodeCom\FurgonetkaApi\Dto\PriceQuoteList;
use VeryCodeCom\FurgonetkaApi\Dto\Regulation;
use VeryCodeCom\FurgonetkaApi\Dto\ServiceList;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The account answers (services, regulations, companies, dictionary, countries) and the price
 * list, parsed from the bodies the sandbox returned.
 */
final class AccountDtosTest extends TestCase
{
    // -- Services ---------------------------------------------------------------------------

    public function testTheServicesOfTheAccount(): void
    {
        $list = ServiceList::fromArray(Fixtures::json('account/services'));

        self::assertCount(16, $list);
        self::assertSame(16, iterator_count($list));

        $dpd = $list->find(12014776);
        self::assertNotNull($dpd);
        self::assertSame('dpd', $dpd->carrierName);
        self::assertSame(Carrier::Dpd, $dpd->carrier);
        self::assertSame('DPD', $dpd->name);
        self::assertSame(AgreementOwner::Furgonetka, $dpd->owner);
        self::assertSame(957, $dpd->pricelistId);
        self::assertFalse($dpd->isUpgradedPricelist);
        self::assertTrue($dpd->codEnabled);
        self::assertFalse($dpd->nationalOnly);
        self::assertArrayHasKey('configuration', $dpd->raw());
    }

    public function testAServiceThatIsNationalOnlyAndHasNoCod(): void
    {
        $service = ServiceList::fromArray(Fixtures::json('account/services'))->find(12014787);

        self::assertNotNull($service);
        self::assertSame(Carrier::Deligoo, $service->carrier);
        self::assertTrue($service->nationalOnly);
        self::assertFalse($service->codEnabled);
    }

    public function testACarrierThisReleaseDoesNotKnowKeepsItsName(): void
    {
        $list = ServiceList::fromArray(Fixtures::json('account/services'));

        $gielda = $list->find(12014791);

        self::assertNotNull($gielda);
        self::assertNull($gielda->carrier);
        self::assertSame('furgonetka_gielda', $gielda->carrierName);
        self::assertSame($gielda, $list->first('furgonetka_gielda'), 'A string finds it where the enum cannot.');
    }

    public function testServicesAreFoundByCarrierAndOwner(): void
    {
        $list = ServiceList::fromArray(Fixtures::json('account/services'));

        self::assertSame(12014781, $list->first(Carrier::InPost)?->id);
        self::assertSame(12014781, $list->first('inpost', AgreementOwner::Furgonetka)?->id);
        self::assertNull($list->first(Carrier::InPost, AgreementOwner::Client), 'The account has no contract of its own with InPost.');
        self::assertSame(12014779, $list->first(Carrier::Gls)?->id);
        self::assertSame([], $list->forCarrier(Carrier::Pallex));
        self::assertNull($list->find(1));
        self::assertCount(1, $list->forCarrier(Carrier::Dpd));
    }

    public function testAnEmptyServiceListIsEmpty(): void
    {
        $list = ServiceList::fromArray(['services' => []]);

        self::assertCount(0, $list);
        self::assertNull($list->first(Carrier::Dpd));
        self::assertSame([], $list->raw()['services']);
    }

    // -- Price list -------------------------------------------------------------------------

    public function testThePriceListHasAQuotePerService(): void
    {
        $prices = PriceQuoteList::fromArray(Fixtures::json('packages/calculate-price'));

        self::assertCount(8, $prices);
        self::assertCount(8, $prices->available());
        self::assertSame(8, iterator_count($prices));

        $dpd = $prices->forServiceId(12014776);
        self::assertNotNull($dpd);
        self::assertSame(Carrier::Dpd, $dpd->carrier);
        self::assertSame(AgreementOwner::Furgonetka, $dpd->agreementOwner);
        self::assertTrue($dpd->available);
        self::assertSame([], $dpd->errors);
        self::assertSame(21.41, $dpd->pricing?->priceGross);
        self::assertSame(17.41, $dpd->pricing->priceNet);
        self::assertSame(23, $dpd->pricing->tax);
        self::assertFalse($dpd->calculationPendingInBackground);
        self::assertSame([], $dpd->additionalFees);
        self::assertNull($dpd->lowestPriceGross);
    }

    public function testTheCheapestQuoteIsTheLowestGrossPrice(): void
    {
        $prices = PriceQuoteList::fromArray(Fixtures::json('packages/calculate-price'));

        self::assertSame(12014782, $prices->cheapest()?->serviceId, 'ORLEN Paczka, 15.98.');
        self::assertSame(15.98, $prices->cheapest()->pricing?->priceGross);
    }

    public function testTheCourierDetailsOfAQuote(): void
    {
        $fedex = PriceQuoteList::fromArray(Fixtures::json('packages/calculate-price'))->forServiceId(12014777);

        self::assertNotNull($fedex);
        self::assertSame(1000, $fedex->courierDetails?->amountFreeInsurance);
        self::assertSame(7, $fedex->courierDetails->returnCodDays);
        self::assertTrue($fedex->courierDetails->codExpress);
        self::assertNull($fedex->courierDetails->parcelLockerPickupDays);
        self::assertSame(4, $fedex->courierDetails->servicePointPickupDays);
        self::assertSame(1394, $fedex->courierDetails->avgDeliveryTime);
    }

    public function testQuotesAreFilteredByCarrier(): void
    {
        $prices = PriceQuoteList::fromArray(Fixtures::json('packages/calculate-price'));

        self::assertSame([12014783], array_map(static fn ($q): ?int => $q->serviceId, $prices->forCarrier(Carrier::Dhl)));
        self::assertSame([12014783], array_map(static fn ($q): ?int => $q->serviceId, $prices->forCarrier('dhl')));
        self::assertSame([12014779], array_map(static fn ($q): ?int => $q->serviceId, $prices->forCarrier(Carrier::Gls)));
        self::assertNull($prices->forServiceId(1));
        self::assertNotNull($prices->raw()['services_prices'] ?? null);
        self::assertSame(12014783, $prices->quotes[1]->raw()['service_id']);
    }

    public function testACarrierThatCannotDeliverIsNotAvailableAndNeverTheCheapest(): void
    {
        $prices = PriceQuoteList::fromArray(['services_prices' => [
            ['service_id' => 1, 'service' => 'dpd', 'available' => false, 'errors' => [['message' => 'Weight above the limit', 'code' => 'weight']], 'pricing' => ['price_gross' => 1.0]],
            ['service_id' => 2, 'service' => 'gls', 'available' => true, 'errors' => [], 'pricing' => ['price_gross' => 30.5, 'price_net' => 24.8]],
            ['service_id' => 3, 'service' => 'ups', 'available' => true, 'errors' => [], 'pricing' => null],
        ]]);

        self::assertCount(3, $prices);
        self::assertSame([2, 3], array_map(static fn ($q): ?int => $q->serviceId, $prices->available()));
        self::assertSame(2, $prices->cheapest()?->serviceId, 'A quote without a price cannot be the cheapest.');

        $dpd = $prices->forServiceId(1);
        self::assertNotNull($dpd);
        self::assertFalse($dpd->available);
        self::assertSame('weight', $dpd->errors[0]->code);
    }

    public function testAQuoteWithoutAvailableCountsAsNotAvailable(): void
    {
        $prices = PriceQuoteList::fromArray(['services_prices' => [['service_id' => 1, 'service' => 'dpd']]]);

        self::assertSame([], $prices->available());
        self::assertNull($prices->cheapest());
    }

    public function testTheAdditionalFeesOfAQuote(): void
    {
        $prices = PriceQuoteList::fromArray(['services_prices' => [[
            'service_id' => 1,
            'service' => 'dpd',
            'available' => true,
            'lowest_price' => ['price_gross' => 19.9],
            'additional_fees' => [['type' => 'oversize', 'amount' => 12.3, 'amount_net' => 10.0, 'description' => 'Oversize parcel', 'charged_items' => ['parcel-1', 'parcel-2']]],
        ]]]);

        $quote = $prices->quotes[0];
        self::assertSame(19.9, $quote->lowestPriceGross);
        self::assertCount(1, $quote->additionalFees);
        self::assertSame('oversize', $quote->additionalFees[0]->type);
        self::assertSame(12.3, $quote->additionalFees[0]->amount);
        self::assertSame(10.0, $quote->additionalFees[0]->amountNet);
        self::assertSame(['parcel-1', 'parcel-2'], $quote->additionalFees[0]->chargedItems);
    }

    // -- Regulations ------------------------------------------------------------------------

    /** @return list<Regulation> */
    private static function regulations(): array
    {
        $items = Fixtures::json('account/regulations')['regulations'] ?? null;
        self::assertIsArray($items);
        $regulations = [];

        foreach ($items as $item) {
            self::assertIsArray($item);
            /** @var array<string, mixed> $item */
            $regulations[] = Regulation::fromArray($item);
        }

        return $regulations;
    }

    public function testTheRegulationsOfTheAccount(): void
    {
        $regulations = self::regulations();

        self::assertSame(['fedex', 'furgonetka_app_push_marketing', 'dpd', 'furgonetka_regulation_privacy'], array_map(static fn (Regulation $r): ?string => $r->service, $regulations));

        [$fedex, $push, $dpd] = $regulations;
        self::assertFalse($fedex->accepted);
        self::assertFalse($fedex->withdrawable);
        self::assertFalse($fedex->marketing);
        self::assertNull($fedex->datetimeAccepted);
        self::assertSame(1785103203, $fedex->version);
        self::assertSame('FedEx Krajowy', $fedex->name);
        self::assertCount(2, $fedex->documents);
        self::assertSame('regulations', $fedex->documents[0]->type);
        self::assertSame('fedex_carriage_conditions_domestic_parcels', $fedex->documents[0]->key);
        self::assertNull($fedex->documents[1]->key, 'An empty string in the answer.');
        self::assertStringStartsWith('https://c.furgonetka.pl/', (string) $fedex->documents[0]->url);

        self::assertTrue($push->marketing);
        self::assertTrue($push->withdrawable);
        self::assertSame([], $push->documents);

        self::assertTrue($dpd->accepted);
        self::assertSame('2026-09-29T07:58:23+02:00', $dpd->datetimeAccepted?->format(\DATE_ATOM));
    }

    public function testARegulationIsAcceptedWithTheDatetimeExactlyAsSent(): void
    {
        $fedex = self::regulations()[0];

        self::assertSame(
            [
                'service' => 'fedex',
                'name' => 'FedEx Krajowy',
                'version' => 1785103203,
                'datetime' => '2026-07-27T00:00:03+02:00',
                'accepted' => true,
            ],
            $fedex->toAcceptance(),
            'Furgonetka checks the version and the datetime against the current ones, so they are passed back untouched.',
        );
    }

    public function testARegulationWithoutAVersionCannotBeAccepted(): void
    {
        $this->expectException(\LogicException::class);

        Regulation::fromArray(['service' => 'dpd', 'datetime' => '2026-07-27T00:00:03+02:00'])->toAcceptance();
    }

    public function testARegulationWithoutANameIsAcceptedUnderItsService(): void
    {
        $data = Regulation::fromArray(['service' => 'dpd', 'version' => 5, 'datetime' => '2026-07-27T00:00:03+02:00'])->toAcceptance();

        self::assertSame('dpd', $data['name']);
    }

    // -- Companies, dictionary, countries ---------------------------------------------------

    public function testACompany(): void
    {
        $companies = Fixtures::json('account/companies')['companies'] ?? null;
        self::assertIsArray($companies);
        self::assertIsArray($companies[0]);
        /** @var array<string, mixed> $first */
        $first = $companies[0];

        $company = Company::fromArray($first);

        self::assertSame(508899, $company->id);
        self::assertSame('Pan Czesio', $company->name);
        self::assertSame('personal', $company->accountType);
        self::assertSame('user', $company->aclRole);
        self::assertTrue($company->actual);
    }

    public function testTheDictionaryLabelsCarriersAndAdditionalServices(): void
    {
        $dictionary = Dictionary::fromArray(Fixtures::json('account/dictionary'));

        self::assertCount(30, $dictionary->carriers());
        self::assertSame('DPD', $dictionary->carriers()['dpd']);
        self::assertSame('ORLEN Paczka', $dictionary->carriers()['orlen']);
        self::assertCount(102, $dictionary->additionalServices());
        self::assertSame('Cash on delivery', $dictionary->additionalServices()['cod']);
        self::assertSame('Parcel insurance', $dictionary->additionalServices()['declaredvalue']);
        self::assertArrayHasKey('services', $dictionary->raw());
    }

    public function testADictionaryWithoutSectionsIsEmpty(): void
    {
        $dictionary = Dictionary::fromArray(['services' => ['dpd' => 'DPD', 'odd' => ['not' => 'a label']]]);

        self::assertSame(['dpd' => 'DPD'], $dictionary->carriers());
        self::assertSame([], $dictionary->additionalServices());
    }

    public function testTheCountriesThatNeedACustomsDeclaration(): void
    {
        $countries = AllowedCountries::fromArray(Fixtures::json('account/allowed-countries'));

        self::assertCount(79, $countries->shipment);
        self::assertCount(79, $countries->delivery);
        self::assertSame('Afghanistan', $countries->shipment[0]->name);
        self::assertSame('AF', $countries->shipment[0]->code);
        self::assertFalse($countries->shipment[0]->duty);
        self::assertSame(
            ['AU', 'BY', 'CA', 'CN', 'HK', 'IN', 'IL', 'NZ', 'NO', 'RS', 'SG', 'CH', 'TR', 'UA', 'GB', 'US'],
            $countries->dutyCountryCodes(),
        );
        self::assertTrue($countries->requiresDuty('GB'));
        self::assertTrue($countries->requiresDuty('gb'), 'The code is compared case-insensitively.');
        self::assertFalse($countries->requiresDuty('DE'));
        self::assertFalse($countries->requiresDuty('ZZ'), 'A country that is not in the list needs no declaration as far as the list knows.');
        self::assertArrayHasKey('delivery', $countries->raw());
    }
}
