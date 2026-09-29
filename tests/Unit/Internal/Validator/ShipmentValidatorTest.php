<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\ExportItem;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Internal\Validator\ShipmentValidator;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * Every rule with a case that breaks it and a case that does not, and for every rule the severity
 * the sandbox showed on 2026-09-29: an error where the API enforces the rule too, a warning where
 * the documentation states it and the API accepts a violation.
 *
 * The line each data set cites, "sandbox:", is what POST /packages/validate answered.
 */
final class ShipmentValidatorTest extends TestCase
{
    private ShipmentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ShipmentValidator();
    }

    /**
     * @param list<string> $dutyCountries
     * @return array{errors: list<string>, warnings: list<string>}
     */
    private function check(ShipmentRequest $request, ?Carrier $carrier = null, ?AgreementOwner $owner = null, array $dutyCountries = []): array
    {
        return $this->validator->check($request, $carrier, $owner, $dutyCountries);
    }

    /** @param list<string> $messages */
    private function assertMentions(array $messages, string $needle): void
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        self::fail("No message mentions \"{$needle}\". Got: " . json_encode($messages));
    }

    /** @param list<string> $messages */
    private function assertNoneMentions(array $messages, string $needle): void
    {
        foreach ($messages as $message) {
            self::assertStringNotContainsString($needle, $message);
        }
    }

    // -- A valid shipment ----------------------------------------------------------

    public function testTheShipmentTheSandboxAcceptedHasNothingToReport(): void
    {
        foreach ([null, Carrier::Dpd] as $carrier) {
            self::assertSame(['errors' => [], 'warnings' => []], $this->check(ShipmentFactory::request(), $carrier));
        }
    }

    public function testValidateAndWarningsAreTheTwoHalvesOfCheck(): void
    {
        $request = ShipmentFactory::request(serviceId: 0, reference: str_repeat('a', 30));
        $result = $this->check($request, Carrier::Fedex);

        self::assertSame($result['errors'], $this->validator->validate($request, Carrier::Fedex));
        self::assertSame($result['warnings'], $this->validator->warnings($request, Carrier::Fedex));
        self::assertNotSame([], $result['errors']);
    }

    // -- Required ---------------------------------------------------------------

    public function testTheServiceIdMustBePositive(): void
    {
        self::assertSame(['/service_id: must be the id of a service on the account (GET /account/services).'], $this->check(ShipmentFactory::request(serviceId: 0))['errors']);
        self::assertSame([], $this->check(ShipmentFactory::request(serviceId: 1))['errors']);
    }

    public function testAtLeastOneParcelIsRequired(): void
    {
        // sandbox: /parcels=0 "Minimalna liczba elementow wynosi 1" for an empty list and a missing key.
        self::assertSame(['/parcels: at least one parcel is required.'], $this->check(ShipmentFactory::request(parcels: []))['errors']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressFields(): iterable
    {
        yield 'street' => ['street'];
        yield 'postcode' => ['postcode'];
        yield 'city' => ['city'];
    }

    #[DataProvider('addressFields')]
    public function testThePickupAddressIsRequired(string $field): void
    {
        // sandbox: /pickup/{street,postcode,city}=isEmpty.
        $pickup = ShipmentFactory::pickup(street: $field === 'street' ? '  ' : 'Marszalkowska 100', postcode: $field === 'postcode' ? '' : '00-026', city: $field === 'city' ? '' : 'Warszawa');

        self::assertSame(["/pickup/{$field}: is required."], $this->check(ShipmentFactory::request(pickup: $pickup))['errors']);
    }

    #[DataProvider('addressFields')]
    public function testTheReceiverAddressIsRequired(string $field): void
    {
        // sandbox: /receiver/{street,postcode,city}=isEmpty.
        $receiver = ShipmentFactory::receiver(street: $field === 'street' ? null : 'Florianska 15', postcode: $field === 'postcode' ? null : '31-021', city: $field === 'city' ? ' ' : 'Krakow');

        self::assertSame(["/receiver/{$field}: is required."], $this->check(ShipmentFactory::request(receiver: $receiver), Carrier::Dpd)['errors']);
    }

    /**
     * @return iterable<string, array{?Carrier, bool}> carrier, whether the missing address is an error
     */
    public static function receiverPoints(): iterable
    {
        yield 'a parcel locker of InPost stands for the address' => [Carrier::InPost, false];
        yield 'an ORLEN Paczka point stands for the address' => [Carrier::Orlen, false];
        yield 'an unknown carrier gets the benefit of the doubt' => [null, false];
        yield 'DPD Pickup does not' => [Carrier::Dpd, true];
        yield 'Poczta does not' => [Carrier::Poczta, true];
    }

    #[DataProvider('receiverPoints')]
    public function testAPointReplacesTheReceiverAddressOnlyWhereTheDocumentationSaysSo(?Carrier $carrier, bool $isError): void
    {
        $receiver = ShipmentFactory::receiver(street: null, postcode: null, city: null, point: 'WAW123M');

        $errors = $this->check(ShipmentFactory::request(receiver: $receiver), $carrier)['errors'];

        self::assertCount($isError ? 3 : 0, $errors);

        if ($isError) {
            $this->assertMentions($errors, 'only InPost parcel lockers and ORLEN Paczka points replace the address');
        }
    }

    public function testABlankPointDoesNotReplaceTheAddress(): void
    {
        $receiver = ShipmentFactory::receiver(street: null, point: '  ');

        self::assertSame(['/receiver/street: is required.'], $this->check(ShipmentFactory::request(receiver: $receiver), Carrier::InPost)['errors']);
    }

    // -- Name and company ----------------------------------------------------------

    public function testANameOrACompanyIsRequiredOfEachParty(): void
    {
        // sandbox: /receiver/name=isEmpty and /receiver/company=isEmpty together.
        $request = ShipmentFactory::request(pickup: ShipmentFactory::pickup(name: null), receiver: ShipmentFactory::receiver(name: ' '));

        self::assertSame([
            '/pickup/name: a name or a company is required.',
            '/receiver/name: a name or a company is required.',
        ], $this->check($request, Carrier::Dpd)['errors']);
    }

    public function testACompanyAloneIsEnough(): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(name: null, company: 'Acme Sp. z o.o.'));

        self::assertSame([], $this->check($request, Carrier::Dpd)['errors']);
    }

    public function testAnInPostReceiverNeedsNeitherButAnInPostPickupStillDoes(): void
    {
        $receiver = ShipmentFactory::receiver(name: null, street: null, postcode: null, city: null, point: 'WAW123M');

        self::assertSame([], $this->check(ShipmentFactory::request(receiver: $receiver), Carrier::InPost)['errors']);
        self::assertSame(
            ['/pickup/name: a name or a company is required.'],
            $this->check(ShipmentFactory::request(pickup: ShipmentFactory::pickup(name: null), receiver: $receiver), Carrier::InPost)['errors'],
        );
    }

    /**
     * @return iterable<string, array{string, bool}> name, whether it is a valid full name
     */
    public static function fullNames(): iterable
    {
        yield 'first and last name' => ['Jan Kowalski', true];
        yield 'two letters each, six in all' => ['Al Kow', true];
        yield 'sandbox: "A B" is too short' => ['A B', false];
        yield 'sandbox: one word is not a full name' => ['Kowalski', false];
        yield 'a one-letter last name' => ['Jan K', false];
        yield 'a one-letter first name' => ['J Kowalski', false];
        yield 'five characters in all' => ['Al Ko', false];
        yield 'three words' => ['Jan Maria Kowalski', true];
        yield 'polish letters count as one character each' => ['Łu Żó', false];
        yield 'polish letters, long enough' => ['Łukasz Żółć', true];
        yield 'surrounding spaces are ignored' => ['  Jan Kowalski  ', true];
    }

    #[DataProvider('fullNames')]
    public function testANameIsAFirstAndALastNameOfAtLeastTwoCharactersEach(string $name, bool $valid): void
    {
        $errors = $this->check(ShipmentFactory::request(receiver: ShipmentFactory::receiver(name: $name)), Carrier::Dpd)['errors'];

        self::assertSame($valid ? [] : ['/receiver/name: must be a first and a last name of at least 2 characters each, 6 in all.'], $errors);
    }

    /**
     * @return iterable<string, array{int, bool}> length, valid
     */
    public static function nameLengths(): iterable
    {
        yield '90 characters' => [90, true];
        yield 'sandbox: 91 characters' => [91, false];
    }

    #[DataProvider('nameLengths')]
    public function testANameHasAGeneralMaximumOfNinetyCharacters(int $length, bool $valid): void
    {
        $name = 'Jan ' . str_repeat('K', $length - 4);

        $errors = $this->check(ShipmentFactory::request(receiver: ShipmentFactory::receiver(name: $name)), Carrier::Dpd)['errors'];

        self::assertSame($valid ? [] : ['/receiver/name: is too long, at most 90 characters.'], $errors);
    }

    /**
     * @return iterable<string, array{string, bool}> company, valid
     */
    public static function companies(): iterable
    {
        yield 'two characters' => ['XY', true];
        yield 'sandbox: one character' => ['X', false];
        yield '80 characters' => [str_repeat('K', 80), true];
        yield 'sandbox: 81 characters' => [str_repeat('K', 81), false];
    }

    #[DataProvider('companies')]
    public function testACompanyIsTwoToEightyCharacters(string $company, bool $valid): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(name: null, company: $company));

        self::assertSame($valid, $this->check($request, Carrier::Dpd)['errors'] === []);
    }

    public function testABlankCompanyCountsAsNone(): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(name: null, company: '   '));

        self::assertSame(['/receiver/name: a name or a company is required.'], $this->check($request, Carrier::Dpd)['errors']);
    }

    // -- Carrier limits on name and company: errors where the sandbox enforces them -------

    /**
     * @return iterable<string, array{Carrier, 'pickup'|'receiver'|'sender', 'name'|'company', int}> carrier, party, field, limit
     */
    public static function enforcedLimits(): iterable
    {
        yield 'sandbox: DHL receiver name 61' => [Carrier::Dhl, 'receiver', 'name', 60];
        yield 'sandbox: DHL pickup name 61' => [Carrier::Dhl, 'pickup', 'name', 60];
        yield 'sandbox: DHL sender name 61' => [Carrier::Dhl, 'sender', 'name', 60];
        yield 'sandbox: Poczta pickup name 61' => [Carrier::Poczta, 'pickup', 'name', 60];
        yield 'sandbox: Poczta receiver name 61' => [Carrier::Poczta, 'receiver', 'name', 60];
        yield 'sandbox: AmbroExpress pickup name 61' => [Carrier::AmbroExpress, 'pickup', 'name', 60];
        yield 'sandbox: AmbroExpress receiver name 61' => [Carrier::AmbroExpress, 'receiver', 'name', 60];
        yield 'sandbox: UPS pickup name 31' => [Carrier::Ups, 'pickup', 'name', 30];
        yield 'sandbox: UPS sender name 31' => [Carrier::Ups, 'sender', 'name', 30];
        yield 'sandbox: DHL company 61' => [Carrier::Dhl, 'receiver', 'company', 60];
        yield 'sandbox: DHL pickup company 61' => [Carrier::Dhl, 'pickup', 'company', 60];
        yield 'sandbox: DHL sender company 61' => [Carrier::Dhl, 'sender', 'company', 60];
        yield 'sandbox: FedEx company 151' => [Carrier::Fedex, 'receiver', 'company', 150];
        yield 'sandbox: FedEx sender company 151' => [Carrier::Fedex, 'sender', 'company', 150];
        yield 'sandbox: Poczta pickup company 31' => [Carrier::Poczta, 'pickup', 'company', 30];
        yield 'sandbox: UPS pickup company 31' => [Carrier::Ups, 'pickup', 'company', 30];
        yield 'sandbox: UPS sender company 31' => [Carrier::Ups, 'sender', 'company', 30];
        yield 'sandbox: AmbroExpress company 61' => [Carrier::AmbroExpress, 'receiver', 'company', 60];
    }

    /**
     * @param 'pickup'|'receiver'|'sender' $party
     * @param 'name'|'company'             $field
     */
    #[DataProvider('enforcedLimits')]
    public function testACarrierLimitTheSandboxEnforcesIsAnError(Carrier $carrier, string $party, string $field, int $limit): void
    {
        $atLimit = $this->requestWith($party, $field, $limit);
        $overLimit = $this->requestWith($party, $field, $limit + 1);

        self::assertSame([], $this->check($atLimit, $carrier)['errors']);
        self::assertSame(["/{$party}/{$field}: is too long, at most {$limit} characters for {$carrier->value}."], $this->check($overLimit, $carrier)['errors']);
        // An enforced limit is an error, never also a warning (a sender that differs from the pickup warns for its own reason).
        self::assertFalse($this->mentions($this->check($overLimit, $carrier)['warnings'], 'documentation limits'));
    }

    /**
     * A request in which one party's name or company has the given length.
     *
     * @param 'pickup'|'receiver'|'sender' $party
     * @param 'name'|'company'             $field
     */
    private function requestWith(string $party, string $field, int $length): ShipmentRequest
    {
        $value = $field === 'name' ? 'Jan ' . str_repeat('K', $length - 4) : str_repeat('K', $length);
        $name = $field === 'name' ? $value : null;
        $company = $field === 'company' ? $value : null;
        // The party that is under test carries the value; the others stay valid.
        $sender = ShipmentFactory::labelSender(name: $party === 'sender' ? $name : 'Piotr Zielinski', company: $party === 'sender' ? $company : null);

        return ShipmentFactory::request(
            pickup: $party === 'pickup' ? ShipmentFactory::pickup(name: $name, company: $company) : ShipmentFactory::pickup(),
            receiver: $party === 'receiver' ? ShipmentFactory::receiver(name: $name, company: $company) : ShipmentFactory::receiver(),
            labelSender: $party === 'sender' ? $sender : null,
        );
    }

    /**
     * @return iterable<string, array{Carrier, 'pickup'|'receiver', 'name'|'company', int}> carrier, party, field, documented limit
     */
    public static function limitsTheSandboxDoesNotEnforce(): iterable
    {
        yield 'sandbox accepted a UPS receiver name of 31' => [Carrier::Ups, 'receiver', 'name', 30];
        yield 'sandbox accepted a Poczta receiver company of 31' => [Carrier::Poczta, 'receiver', 'company', 30];
        yield 'sandbox accepted a UPS receiver company of 31' => [Carrier::Ups, 'receiver', 'company', 30];
        yield 'ORLEN Paczka pickup company could not be checked' => [Carrier::Orlen, 'pickup', 'company', 30];
        yield 'ORLEN Paczka receiver company could not be checked' => [Carrier::Orlen, 'receiver', 'company', 30];
    }

    /**
     * @param 'pickup'|'receiver' $party
     * @param 'name'|'company'    $field
     */
    #[DataProvider('limitsTheSandboxDoesNotEnforce')]
    public function testADocumentedLimitTheSandboxAcceptsAViolationOfIsAWarning(Carrier $carrier, string $party, string $field, int $limit): void
    {
        $result = $this->check($this->requestWith($party, $field, $limit + 1), $carrier);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['warnings']);
        $this->assertMentions($result['warnings'], "/{$party}/{$field}: the documentation limits it to {$limit} characters for {$carrier->value}");
        self::assertSame([], $this->check($this->requestWith($party, $field, $limit), $carrier)['warnings']);
    }

    public function testFedexCompanyOfEightyOneToOneHundredFiftyIsFine(): void
    {
        // sandbox: 81 and 150 accepted, 151 refused; the general 80 is relaxed for FedEx.
        $request = $this->requestWith('receiver', 'company', 150);

        self::assertSame(['errors' => [], 'warnings' => []], $this->check($request, Carrier::Fedex));
        self::assertNotSame([], $this->check($request, Carrier::Dpd)['errors']);
    }

    public function testCarrierLimitsApplyOnlyWhenTheCarrierIsKnown(): void
    {
        $request = $this->requestWith('receiver', 'name', 61);

        self::assertSame([], $this->check($request)['errors']);
        self::assertNotSame([], $this->check($request, Carrier::Dhl)['errors']);
    }

    public function testLengthsAreCountedInCharactersNotBytes(): void
    {
        // 60 characters but 120 bytes: within DHL's 60.
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(name: 'Łukasz ' . str_repeat('Ż', 53)));

        self::assertSame([], $this->check($request, Carrier::Dhl)['errors']);
    }

    // -- County ----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, ?string, bool}> country, county, error expected
     */
    public static function counties(): iterable
    {
        yield 'sandbox: the United States without a state' => ['US', null, true];
        yield 'the United States with a state' => ['US', 'NY', false];
        yield 'Ireland without a county' => ['IE', null, true];
        yield 'Ireland with a county' => ['IE', 'Dublin', false];
        yield 'Canada without a province' => ['CA', ' ', true];
        yield 'Canada with a province' => ['CA', 'ON', false];
        yield 'lower case country code' => ['us', null, true];
        yield 'Poland needs none' => ['PL', null, false];
        yield 'Germany needs none' => ['DE', null, false];
    }

    #[DataProvider('counties')]
    public function testCountyIsRequiredForIrelandTheUnitedStatesAndCanada(string $country, ?string $county, bool $isError): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: $country, county: $county), duty: new Duty([new ExportItem()], DutyType::Gift));
        $errors = $this->check($request)['errors'];

        self::assertSame($isError, $errors !== []);

        if ($isError) {
            self::assertSame(['/receiver/county: is required for ' . strtoupper($country) . ' (county for IE, state for US, province for CA).'], $errors);
        }
    }

    public function testCountyIsRequiredOfThePickupAndTheLabelSenderToo(): void
    {
        $request = ShipmentFactory::request(
            pickup: ShipmentFactory::pickup(country: 'IE'),
            labelSender: ShipmentFactory::labelSender(country: 'CA'),
        );

        self::assertSame([
            '/pickup/county: is required for IE (county for IE, state for US, province for CA).',
            '/sender/county: is required for CA (county for IE, state for US, province for CA).',
        ], $this->check($request)['errors']);
    }

    // -- Number of parcels ------------------------------------------------------------

    /**
     * @param list<ParcelType> $types
     * @return list<\VeryCodeCom\FurgonetkaApi\Dto\Parcel>
     */
    private function parcels(int $count, array $types = []): array
    {
        $parcels = [];

        for ($i = 0; $i < $count; $i++) {
            $parcels[] = ShipmentFactory::parcel($types[$i] ?? $types[0] ?? ParcelType::Package);
        }

        return $parcels;
    }

    /**
     * @return iterable<string, array{Carrier, ?AgreementOwner, ParcelType, int, string}> carrier, owner, type, count, expected: ok, error or warning
     */
    public static function parcelCounts(): iterable
    {
        yield 'sandbox: DPD 15' => [Carrier::Dpd, null, ParcelType::Package, 15, 'ok'];
        yield 'sandbox: DPD 16' => [Carrier::Dpd, null, ParcelType::Package, 16, 'error'];
        yield 'sandbox: DPD envelope 1' => [Carrier::Dpd, null, ParcelType::Dox, 1, 'ok'];
        yield 'sandbox: DPD envelopes 2 ("Multipack is not available")' => [Carrier::Dpd, null, ParcelType::Dox, 2, 'error'];
        yield 'sandbox: GLS 15' => [Carrier::Gls, null, ParcelType::Package, 15, 'ok'];
        yield 'sandbox: GLS 16' => [Carrier::Gls, null, ParcelType::Package, 16, 'error'];
        yield 'sandbox: FedEx 15' => [Carrier::Fedex, null, ParcelType::Package, 15, 'ok'];
        yield 'sandbox: FedEx 16' => [Carrier::Fedex, null, ParcelType::Package, 16, 'error'];
        yield 'sandbox: DHL 15' => [Carrier::Dhl, null, ParcelType::Package, 15, 'ok'];
        yield 'sandbox: DHL 16' => [Carrier::Dhl, null, ParcelType::Package, 16, 'error'];
        yield 'sandbox: DHL pallets 5' => [Carrier::Dhl, null, ParcelType::Pallet, 5, 'ok'];
        yield 'sandbox: DHL pallets 6 ("A maximum of 5 pallets")' => [Carrier::Dhl, null, ParcelType::Pallet, 6, 'error'];
        yield 'sandbox: UPS on Furgonetka 1' => [Carrier::Ups, AgreementOwner::Furgonetka, ParcelType::Package, 1, 'ok'];
        yield 'sandbox: UPS on Furgonetka 2 ("Multipack is not available")' => [Carrier::Ups, AgreementOwner::Furgonetka, ParcelType::Package, 2, 'error'];
        yield 'UPS on an own contract 15 (documented)' => [Carrier::Ups, AgreementOwner::Client, ParcelType::Package, 15, 'ok'];
        yield 'UPS on an own contract 16 (documented)' => [Carrier::Ups, AgreementOwner::Client, ParcelType::Package, 16, 'warning'];
        yield 'UPS with the contract unknown, 1' => [Carrier::Ups, null, ParcelType::Package, 1, 'ok'];
        yield 'UPS with the contract unknown, 2' => [Carrier::Ups, null, ParcelType::Package, 2, 'warning'];
        yield 'sandbox: Poczta 1' => [Carrier::Poczta, null, ParcelType::Package, 1, 'ok'];
        yield 'sandbox: Poczta 2 (documented 1, accepted)' => [Carrier::Poczta, null, ParcelType::Package, 2, 'warning'];
        yield 'sandbox: Poczta 10 (documented 1, accepted)' => [Carrier::Poczta, null, ParcelType::Package, 10, 'warning'];
        yield 'sandbox: Poczta 11 ("A maximum of 10")' => [Carrier::Poczta, null, ParcelType::Package, 11, 'error'];
        yield 'InPost 1' => [Carrier::InPost, null, ParcelType::Package, 1, 'ok'];
        yield 'InPost 2 (documented, not checked)' => [Carrier::InPost, null, ParcelType::Package, 2, 'warning'];
        yield 'InPost Kurier 15' => [Carrier::InPostKurier, null, ParcelType::Package, 15, 'ok'];
        yield 'InPost Kurier 16 (documented, not checked)' => [Carrier::InPostKurier, null, ParcelType::Package, 16, 'warning'];
        yield 'ORLEN 2 (documented, not checked)' => [Carrier::Orlen, null, ParcelType::Package, 2, 'warning'];
        yield 'DHL Express 2 (documented, not checked)' => [Carrier::DhlInternational, null, ParcelType::Package, 2, 'warning'];
        yield 'a carrier without a documented limit' => [Carrier::Meest, null, ParcelType::Package, 50, 'ok'];
        yield 'AmbroExpress has none documented, the API decides' => [Carrier::AmbroExpress, null, ParcelType::Package, 50, 'ok'];
    }

    #[DataProvider('parcelCounts')]
    public function testTheNumberOfParcelsIsLimitedPerCarrier(Carrier $carrier, ?AgreementOwner $owner, ParcelType $type, int $count, string $expected): void
    {
        $result = $this->check(ShipmentFactory::request(parcels: $this->parcels($count, [$type])), $carrier, $owner);
        $onlyParcels = static fn (array $messages): array => array_values(array_filter($messages, static fn (string $m): bool => str_starts_with($m, '/parcels:')));

        self::assertCount($expected === 'error' ? 1 : 0, $onlyParcels($result['errors']), 'errors');
        self::assertCount($expected === 'warning' ? 1 : 0, $onlyParcels($result['warnings']), 'warnings');
    }

    public function testDhlPalletsAreCountedAsPalletsAndMixedLoadsAreNot(): void
    {
        $sixMixed = $this->parcels(6, [ParcelType::Pallet, ParcelType::Package, ParcelType::Package, ParcelType::Package, ParcelType::Package, ParcelType::Package]);

        self::assertSame([], $this->check(ShipmentFactory::request(parcels: $sixMixed), Carrier::Dhl)['errors'], 'Only a load of pallets is limited to 5.');
        $this->assertMentions($this->check(ShipmentFactory::request(parcels: $this->parcels(6, [ParcelType::Pallet])), Carrier::Dhl)['errors'], 'at most 5 pallets per shipment for dhl, got 6');
    }

    public function testAnEnvelopeAnywhereInTheShipmentLimitsDpdToOneParcel(): void
    {
        $parcels = [ShipmentFactory::parcel(), ShipmentFactory::parcel(ParcelType::Dox)];

        $this->assertMentions($this->check(ShipmentFactory::request(parcels: $parcels), Carrier::Dpd)['errors'], 'at most 1 parcels per shipment for dpd, got 2');
    }

    public function testTheParcelCountIsNotCheckedWithoutACarrier(): void
    {
        self::assertSame([], $this->check(ShipmentFactory::request(parcels: $this->parcels(50)))['errors']);
    }

    // -- Parcel description ------------------------------------------------------------

    public function testADescriptionOfOneCharacterIsAnError(): void
    {
        // sandbox: /parcels/0/description=stringLengthTooShort for 1 character, 2 accepted.
        $short = $this->check(ShipmentFactory::request(parcels: [ShipmentFactory::parcel(description: 'x')]), Carrier::Dpd);
        $two = $this->check(ShipmentFactory::request(parcels: [ShipmentFactory::parcel(description: 'xy')]), Carrier::Dpd);

        self::assertSame(['/parcels/0/description: must have at least 2 characters.'], $short['errors']);
        self::assertSame([], $two['errors']);
    }

    /**
     * @return iterable<string, array{Carrier, int, string}> carrier, description length, outcome
     */
    public static function descriptionLengths(): iterable
    {
        yield 'sandbox: DHL 30' => [Carrier::Dhl, 30, 'ok'];
        yield 'sandbox: DHL 31' => [Carrier::Dhl, 31, 'error'];
        yield 'sandbox: UPS 50' => [Carrier::Ups, 50, 'ok'];
        yield 'sandbox: UPS 51' => [Carrier::Ups, 51, 'error'];
        yield 'sandbox: DPD 100' => [Carrier::Dpd, 100, 'ok'];
        yield 'sandbox: DPD 101 (documented 100, accepted)' => [Carrier::Dpd, 101, 'warning'];
        yield 'sandbox: FedEx 101 (documented 100, accepted)' => [Carrier::Fedex, 101, 'warning'];
    }

    #[DataProvider('descriptionLengths')]
    public function testTheDescriptionLengthLimitsOfTheSandbox(Carrier $carrier, int $length, string $outcome): void
    {
        $request = ShipmentFactory::request(parcels: [ShipmentFactory::parcel(description: str_repeat('x', $length))]);

        $result = $this->check($request, $carrier);

        self::assertCount($outcome === 'error' ? 1 : 0, $result['errors']);
        self::assertCount($outcome === 'warning' ? 1 : 0, $result['warnings']);
    }

    public function testTheDescriptionIsCheckedForEachParcelAndNamedByIndex(): void
    {
        $request = ShipmentFactory::request(parcels: [ShipmentFactory::parcel(), ShipmentFactory::parcel(description: 'x')]);

        self::assertSame(['/parcels/1/description: must have at least 2 characters.'], $this->check($request)['errors']);
    }

    /**
     * @return iterable<string, array{Carrier, ?string, bool}> carrier, description, warning expected
     */
    public static function requiredDescriptions(): iterable
    {
        yield 'sandbox: DHL without one validated (204)' => [Carrier::Dhl, null, true];
        yield 'sandbox: FedEx without one validated (204)' => [Carrier::Fedex, null, true];
        yield 'sandbox: Poczta without one validated (204)' => [Carrier::Poczta, null, true];
        yield 'a blank one is none' => [Carrier::Dhl, '   ', true];
        yield 'DHL with one' => [Carrier::Dhl, 'Ksiazki', false];
        yield 'DPD does not need one' => [Carrier::Dpd, null, false];
        yield 'UPS does not need one' => [Carrier::Ups, null, false];
        yield 'GLS does not need one' => [Carrier::Gls, null, false];
    }

    #[DataProvider('requiredDescriptions')]
    public function testADescriptionDocumentedAsRequiredIsOnlyAWarningBecauseTheApiValidatesWithoutIt(Carrier $carrier, ?string $description, bool $warns): void
    {
        $result = $this->check(ShipmentFactory::request(parcels: [ShipmentFactory::parcel(description: $description)]), $carrier);

        self::assertSame([], $result['errors']);
        self::assertCount($warns ? 1 : 0, $result['warnings']);
    }

    public function testTheTypeDependentFieldsAreWarningsBecauseNoCarrierOfAFreshAccountTakesThoseTypes(): void
    {
        $request = ShipmentFactory::request(parcels: [
            ShipmentFactory::parcel(ParcelType::Furniture),
            ShipmentFactory::parcel(ParcelType::Machines, quantity: 2),
            ShipmentFactory::parcel(ParcelType::Other),
            ShipmentFactory::parcel(ParcelType::Vehicles, brand: 'Opel'),
        ]);

        $result = $this->check($request);

        self::assertSame([], $result['errors']);
        self::assertSame([
            '/parcels/0/quantity: documented as needed for the type furniture.',
            '/parcels/2/quantity: documented as needed for the type other.',
            '/parcels/3/model: documented as needed for the type vehicles.',
        ], $result['warnings']);
    }

    // -- Additional services -------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}> every pair of the eight Poczta services
     */
    public static function pocztaPairs(): iterable
    {
        $group = AdditionalServices::POCZTA_EXCLUSIVE;

        foreach ($group as $i => $first) {
            foreach (array_slice($group, $i + 1) as $second) {
                yield "{$first} + {$second}" => [$first, $second];
            }
        }
    }

    #[DataProvider('pocztaPairs')]
    public function testTwoOfTheEightPocztaServicesExcludeEachOther(string $first, string $second): void
    {
        // sandbox: `noTwo` "Only one of the services can be selected." (checked for pocztex + kurier48, kurier48 + company letter).
        $services = new AdditionalServices(extra: []);
        $services = (new \ReflectionClass(AdditionalServices::class))->newInstanceArgs([
            $this->camel($first) => true,
            $this->camel($second) => true,
        ]);

        self::assertSame(
            ["/additional_services: only one of the Poczta services can be selected, got {$first}, {$second}."],
            $this->check(ShipmentFactory::request(services: $services), Carrier::Poczta)['errors'],
        );
    }

    private function camel(string $key): string
    {
        return match ($key) {
            'poczta_kurier48' => 'pocztaKurier48',
            default => lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key)))),
        };
    }

    public function testOneOrNoneOfThePocztaServicesIsFine(): void
    {
        foreach ([new AdditionalServices(pocztex: true), new AdditionalServices(pocztex: true, pocztaKurier48: false), new AdditionalServices(rod: true)] as $services) {
            self::assertSame([], $this->check(ShipmentFactory::request(services: $services), Carrier::Poczta)['errors']);
        }
    }

    public function testCudAndRodExcludeEachOther(): void
    {
        // sandbox: /additional_services/rod=disableForTwo for DPD.
        $both = new AdditionalServices(rod: true, cud: true);

        self::assertSame(['/additional_services/cud: excludes rod, only one of them can be selected.'], $this->check(ShipmentFactory::request(services: $both), Carrier::Dpd)['errors']);
        self::assertSame([], $this->check(ShipmentFactory::request(services: new AdditionalServices(cud: true, rod: false)))['errors']);
        self::assertSame([], $this->check(ShipmentFactory::request(services: new AdditionalServices(cud: true)))['errors']);
    }

    public function testSaturdayDeliveryIsAWarningBecauseTheHandoverDateIsNotPartOfTheShipment(): void
    {
        $result = $this->check(ShipmentFactory::request(services: new AdditionalServices(saturdayDelivery: true)), Carrier::Dpd);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['warnings']);
        $this->assertMentions($result['warnings'], 'must be handed over on a Friday');
        self::assertSame([], $this->check(ShipmentFactory::request(services: new AdditionalServices(saturdayDelivery: false)))['warnings']);
    }

    // -- COD ---------------------------------------------------------------------------

    /**
     * @return iterable<string, array{Carrier, float, list<?float>, string}> carrier, COD amount, parcel values, outcome
     */
    public static function codCases(): iterable
    {
        yield 'sandbox: DHL 500 with value 500' => [Carrier::Dhl, 500.0, [500.0], 'ok'];
        yield 'sandbox: DHL 500 with value 499' => [Carrier::Dhl, 500.0, [499.0], 'error'];
        yield 'sandbox: FedEx 300 with value 299' => [Carrier::Fedex, 300.0, [299.0], 'error'];
        yield 'sandbox: FedEx 100 with value 100' => [Carrier::Fedex, 100.0, [100.0], 'ok'];
        yield 'sandbox: Poczta 150 with value 100' => [Carrier::Poczta, 150.0, [100.0], 'error'];
        yield 'sandbox: DHL 500 over two parcels of 250' => [Carrier::Dhl, 500.0, [250.0, 250.0], 'ok'];
        yield 'sandbox: DHL 500 over 250 and 249' => [Carrier::Dhl, 500.0, [250.0, 249.0], 'error'];
        yield 'sandbox: DHL 80 with no value at all' => [Carrier::Dhl, 80.0, [null], 'error'];
        yield 'sandbox: DPD 500 with value 100 was accepted' => [Carrier::Dpd, 500.0, [100.0], 'warning'];
        yield 'sandbox: DPD 150 over two parcels of 100 was accepted' => [Carrier::Dpd, 150.0, [100.0, 100.0], 'ok'];
        yield 'an unknown carrier gets a warning' => [Carrier::Meest, 500.0, [100.0], 'warning'];
    }

    /**
     * @param list<?float> $values
     */
    #[DataProvider('codCases')]
    public function testTheCodMustBeCoveredByTheDeclaredValue(Carrier $carrier, float $amount, array $values, string $outcome): void
    {
        $parcels = array_map(static fn (?float $value) => ShipmentFactory::parcel(value: $value), $values);
        $request = ShipmentFactory::request(parcels: $parcels, services: new AdditionalServices(cod: new Cod($amount)));

        $result = $this->check($request, $carrier);
        $codErrors = array_values(array_filter($result['errors'], static fn (string $m): bool => str_contains($m, 'cod')));
        $codWarnings = array_values(array_filter($result['warnings'], static fn (string $m): bool => str_contains($m, 'cod')));

        self::assertCount($outcome === 'error' ? 1 : 0, $codErrors);
        self::assertCount($outcome === 'warning' ? 1 : 0, $codWarnings);
    }

    public function testACodWithoutACarrierIsAWarning(): void
    {
        $request = ShipmentFactory::request(services: new AdditionalServices(cod: new Cod(500.0)));

        self::assertSame([], $this->check($request)['errors']);
        self::assertCount(1, $this->check($request)['warnings']);
    }

    public function testACodInAnotherCurrencyIsNotComparedWithAValueInZloty(): void
    {
        $request = ShipmentFactory::request(services: new AdditionalServices(cod: new Cod(500.0, 'EUR')));

        self::assertSame(['errors' => [], 'warnings' => []], $this->check($request, Carrier::Dhl));
    }

    public function testACodInZlotyIsComparedWhetherOrNotTheCurrencyIsSpelledOut(): void
    {
        $request = ShipmentFactory::request(services: new AdditionalServices(cod: new Cod(500.0, 'pln')));

        self::assertCount(1, $this->check($request, Carrier::Dhl)['errors']);
    }

    public function testFractionsOfAGroszDoNotMakeACoveredCodAnError(): void
    {
        $request = ShipmentFactory::request(parcels: [ShipmentFactory::parcel(value: 33.33), ShipmentFactory::parcel(value: 66.67)], services: new AdditionalServices(cod: new Cod(100.0)));

        self::assertSame([], $this->check($request, Carrier::Dhl)['errors']);
    }

    // -- Duty --------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function dutyCountries(): iterable
    {
        foreach (ShipmentValidator::DUTY_COUNTRIES as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('dutyCountries')]
    public function testDutyIsRequiredForTheDestinationsOfTheList(string $country): void
    {
        // sandbox: `/duty=isEmpty` for UPS and GLS to Great Britain.
        $receiver = ShipmentFactory::receiver(country: $country, county: 'XX');
        $errors = $this->check(ShipmentFactory::request(receiver: $receiver), Carrier::Ups)['errors'];

        self::assertSame(["/duty: is required for shipments to {$country}."], $errors);
    }

    public function testDutyPresentSatisfiesTheRequirement(): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'GB'), duty: new Duty([new ExportItem(nameMaterial: 'Books')], DutyType::Gift));

        self::assertSame([], $this->check($request, Carrier::Ups)['errors']);
    }

    public function testTheCountryCodeIsReadInAnyCase(): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'gb'));

        self::assertSame(['/duty: is required for shipments to GB.'], $this->check($request)['errors']);
    }

    public function testNoDutyIsNeededDomesticallyOrWithinTheEuEvenForUpsFedexAndGls(): void
    {
        // sandbox, W17: UPS, FedEx and GLS accepted PL->PL and PL->DE without duty (204), so the
        // documentation's "required for UPS, FedEx, Meest, GLS, NovaPost" follows the destination.
        foreach ([Carrier::Ups, Carrier::Fedex, Carrier::Gls, Carrier::Meest, Carrier::NovaPost] as $carrier) {
            foreach ([null, 'PL', 'DE'] as $country) {
                $result = $this->check(ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: $country)), $carrier);

                self::assertSame([], $result['errors'], "{$carrier->value} to " . ($country ?? 'nowhere'));
                self::assertSame([], $result['warnings'], "{$carrier->value} to " . ($country ?? 'nowhere'));
            }
        }
    }

    public function testCountriesFlaggedByTheApiAreRequiredToo(): void
    {
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'AE'));

        self::assertSame([], $this->check($request)['errors']);
        self::assertSame(['/duty: is required for shipments to AE.'], $this->check($request, null, null, ['ae'])['errors']);
    }

    /**
     * @return iterable<string, array{?DutyType, ?string, bool}> purpose, EORI, error expected
     */
    public static function eori(): iterable
    {
        yield 'sandbox: a sale without EORI' => [DutyType::Sale, null, true];
        yield 'a sale with a blank EORI' => [DutyType::Sale, '  ', true];
        yield 'a sale with EORI' => [DutyType::Sale, 'PL123456789012345', false];
        yield 'sandbox: a gift without EORI' => [DutyType::Gift, null, false];
        yield 'documents in an envelope without EORI' => [DutyType::DocsInDox, null, false];
        yield 'no purpose given' => [null, null, false];
    }

    #[DataProvider('eori')]
    public function testASaleNeedsTheSendersEori(?DutyType $type, ?string $eori, bool $isError): void
    {
        $duty = new Duty([new ExportItem(nameMaterial: 'Books')], $type, eori: $eori);

        $errors = $this->check(ShipmentFactory::request(duty: $duty))['errors'];

        self::assertSame($isError ? ['/duty/eori: is required when the purpose is a sale.'] : [], $errors);
    }

    // -- Reference number ---------------------------------------------------------------

    /**
     * @return iterable<string, array{?Carrier, int, string}> carrier, length, outcome
     */
    public static function references(): iterable
    {
        yield 'DPD 27' => [Carrier::Dpd, 27, 'ok'];
        yield 'sandbox: DPD 28 (documented 27, accepted)' => [Carrier::Dpd, 28, 'warning'];
        yield 'sandbox: DPD 36 (accepted)' => [Carrier::Dpd, 36, 'warning'];
        yield 'sandbox: DPD 37 ("The maximum number of characters is 36")' => [Carrier::Dpd, 37, 'error'];
        yield 'FedEx 30' => [Carrier::Fedex, 30, 'ok'];
        yield 'sandbox: FedEx 31 (documented 30, accepted)' => [Carrier::Fedex, 31, 'warning'];
        yield 'UPS 35' => [Carrier::Ups, 35, 'ok'];
        yield 'sandbox: UPS 36 (documented 35, accepted)' => [Carrier::Ups, 36, 'warning'];
        yield 'GLS 25' => [Carrier::Gls, 25, 'ok'];
        yield 'sandbox: GLS 26 (documented 25, accepted)' => [Carrier::Gls, 26, 'warning'];
        yield 'InPost 28' => [Carrier::InPost, 28, 'ok'];
        yield 'InPost 29 (documented, not checked)' => [Carrier::InPost, 29, 'warning'];
        yield 'InPost Kurier has no documented limit' => [Carrier::InPostKurier, 100, 'ok'];
        yield 'an unknown carrier' => [null, 100, 'ok'];
    }

    #[DataProvider('references')]
    public function testTheReferenceNumberLimits(?Carrier $carrier, int $length, string $outcome): void
    {
        $result = $this->check(ShipmentFactory::request(reference: str_repeat('a', $length)), $carrier);

        self::assertCount($outcome === 'error' ? 1 : 0, $result['errors']);
        self::assertCount($outcome === 'warning' ? 1 : 0, $result['warnings']);
    }

    public function testTheReferenceNumberIsCountedInCharacters(): void
    {
        $request = ShipmentFactory::request(reference: str_repeat('Ż', 27));

        self::assertSame(['errors' => [], 'warnings' => []], $this->check($request, Carrier::Dpd));
    }

    // -- Label sender ---------------------------------------------------------------------

    public function testASenderThatDiffersFromThePickupWarnsThatReturnsGoToTheLabel(): void
    {
        $result = $this->check(ShipmentFactory::request(labelSender: ShipmentFactory::labelSender()), Carrier::Dpd);

        self::assertSame([], $result['errors']);
        self::assertSame(['/sender: differs from the pickup address, so returns go to the address on the label, not to the pickup address.'], $result['warnings']);
    }

    public function testASenderThatMatchesThePickupDoesNotWarnWhateverTheCaseOrSpacing(): void
    {
        $sender = ShipmentFactory::labelSender(name: '  jan KOWALSKI ', street: 'marszalkowska 100', postcode: '00-026', city: 'WARSZAWA', country: 'pl');

        self::assertSame(['errors' => [], 'warnings' => []], $this->check(ShipmentFactory::request(labelSender: $sender), Carrier::Dpd));
    }

    public function testAnAbsentCountryOnEitherSideMeansPoland(): void
    {
        $sender = ShipmentFactory::labelSender(name: 'Jan Kowalski', street: 'Marszalkowska 100', postcode: '00-026', city: 'Warszawa', country: null);

        self::assertSame([], $this->check(ShipmentFactory::request(pickup: ShipmentFactory::pickup(country: null), labelSender: $sender), Carrier::Dpd)['warnings']);
        self::assertSame([], $this->check(ShipmentFactory::request(pickup: ShipmentFactory::pickup(country: 'PL'), labelSender: $sender), Carrier::Dpd)['warnings']);
    }

    /**
     * @return iterable<string, array{Carrier, bool}> carrier, "not documented" warning expected
     */
    public static function labelSenderCarriers(): iterable
    {
        foreach ([Carrier::Dpd, Carrier::Dhl, Carrier::Fedex, Carrier::Ups, Carrier::InPost, Carrier::InPostKurier, Carrier::AmbroExpress] as $carrier) {
            yield "{$carrier->value} is listed" => [$carrier, false];
        }

        yield 'sandbox: GLS is refused by the API' => [Carrier::Gls, true];
        yield 'Poczta only with Ekspres24' => [Carrier::Poczta, true];
        yield 'Meest' => [Carrier::Meest, true];
    }

    #[DataProvider('labelSenderCarriers')]
    public function testOtherDataOnTheLabelIsDocumentedForSomeCarriersOnly(Carrier $carrier, bool $warns): void
    {
        $warnings = $this->check(ShipmentFactory::request(labelSender: ShipmentFactory::labelSender()), $carrier)['warnings'];

        self::assertSame($warns, $this->mentions($warnings, 'does not list'));
    }

    /** @param list<string> $messages */
    private function mentions(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testPocztaEkspres24MakesTheLabelSenderDocumented(): void
    {
        $request = ShipmentFactory::request(labelSender: ShipmentFactory::labelSender(), services: new AdditionalServices(pocztaEkspres24: true));

        self::assertFalse($this->mentions($this->check($request, Carrier::Poczta)['warnings'], 'does not list'));
    }

    public function testWithoutACarrierNothingIsSaidAboutWhoMayUseTheLabelSender(): void
    {
        self::assertFalse($this->mentions($this->check(ShipmentFactory::request(labelSender: ShipmentFactory::labelSender()))['warnings'], 'does not list'));
    }

    public function testTheLabelSenderNeedsAddressAndANameOrACompany(): void
    {
        $sender = new \VeryCodeCom\FurgonetkaApi\Dto\LabelSender(' ', '', ' ');

        self::assertSame([
            '/sender/name: a name or a company is required.',
            '/sender/street: is required.',
            '/sender/postcode: is required.',
            '/sender/city: is required.',
        ], $this->check(ShipmentFactory::request(labelSender: $sender), Carrier::Dpd)['errors']);
    }

    public function testNoLabelSenderNoSenderMessages(): void
    {
        self::assertSame([], $this->check(ShipmentFactory::request(), Carrier::Gls)['warnings']);
    }

    // -- What the messages contain ----------------------------------------------------------

    public function testMessagesNeverQuoteWhatWasEntered(): void
    {
        // Every message is logged, and a value is a name, an address or a phone number.
        $request = ShipmentFactory::request(
            pickup: ShipmentFactory::pickup(name: 'Zenobiusz', company: str_repeat('Kowalczyk', 12), street: 'Ulica Sekretna 7', postcode: '', city: 'Miasteczko'),
            receiver: ShipmentFactory::receiver(name: 'Tajemniczy Odbiorca-' . str_repeat('x', 60), country: 'US'),
            parcels: [ShipmentFactory::parcel(description: str_repeat('Tajny opis ', 12))],
            reference: 'sekretny-numer-' . str_repeat('9', 40),
            labelSender: ShipmentFactory::labelSender(name: 'Nadawca Sekretny', street: 'Inna Ulica 9'),
        );

        $messages = [...$this->check($request, Carrier::Dpd)['errors'], ...$this->check($request, Carrier::Dpd)['warnings']];

        self::assertGreaterThan(4, count($messages));

        foreach (['Zenobiusz', 'Kowalczyk', 'Sekretna', 'Miasteczko', 'Tajemniczy', 'Tajny', 'sekretny', 'Nadawca', 'Inna Ulica', 'jan@example.com', '500600700'] as $secret) {
            $this->assertNoneMentions($messages, $secret);
        }
    }

    public function testEveryMessageStartsWithTheFieldPathTheApiWouldUse(): void
    {
        $request = ShipmentFactory::request(serviceId: 0, parcels: [], pickup: ShipmentFactory::pickup(street: ''), receiver: ShipmentFactory::receiver(name: null));

        foreach ($this->check($request, Carrier::Dpd)['errors'] as $message) {
            self::assertMatchesRegularExpression('#^/[a-z_]+(/[a-z0-9_]+)*: #', $message);
        }
    }
}
