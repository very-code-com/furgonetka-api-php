<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\ExportItem;
use VeryCodeCom\FurgonetkaApi\Dto\LabelOptions;
use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Payer;
use VeryCodeCom\FurgonetkaApi\Dto\Pickup;
use VeryCodeCom\FurgonetkaApi\Dto\PickupDate;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DutyDocumentsOwner;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;

/** The small request DTOs: what each writes, under which key, and what it leaves out. */
final class RequestDtosTest extends TestCase
{
    public function testPickupWritesEveryFieldUnderItsApiKey(): void
    {
        $pickup = new Pickup('Marszalkowska 100', '00-026', 'Warszawa', 'Jan Kowalski', 'Acme', 'PL', null, 'jan@example.com', '500600700', 'any_apm');

        self::assertSame([
            'name' => 'Jan Kowalski', 'company' => 'Acme', 'street' => 'Marszalkowska 100', 'postcode' => '00-026', 'city' => 'Warszawa',
            'country_code' => 'PL', 'email' => 'jan@example.com', 'phone' => '500600700', 'point' => 'any_apm',
        ], $pickup->toArray());
    }

    public function testPickupWithOnlyTheRequiredFieldsWritesOnlyThose(): void
    {
        self::assertSame(['street' => 'A 1', 'postcode' => '00-001', 'city' => 'B'], (new Pickup('A 1', '00-001', 'B'))->toArray());
    }

    public function testReceiverMayOmitTheAddressForAPoint(): void
    {
        $receiver = new Receiver(name: 'Anna Nowak', phone: '600700800', point: 'WAW123M');

        self::assertSame(['name' => 'Anna Nowak', 'phone' => '600700800', 'point' => 'WAW123M'], $receiver->toArray());
    }

    public function testReceiverWritesCountyForIreland(): void
    {
        self::assertSame(['country_code' => 'IE', 'county' => 'Dublin'], (new Receiver(countryCode: 'IE', county: 'Dublin'))->toArray());
    }

    public function testLabelSenderIsWrittenAsTheSenderStructure(): void
    {
        $sender = new LabelSender('Prosta 1', '00-838', 'Warszawa', 'Piotr Zielinski', null, null, null, 's@example.com', '500100200');

        self::assertSame([
            'name' => 'Piotr Zielinski', 'street' => 'Prosta 1', 'postcode' => '00-838', 'city' => 'Warszawa', 'email' => 's@example.com', 'phone' => '500100200',
        ], $sender->toArray());
    }

    public function testParcelPackageFactoryFillsTheMeasures(): void
    {
        $parcel = Parcel::package(30, 40, 20, 5.0, 100.0, 'Ksiazki');

        self::assertSame(ParcelType::Package, $parcel->type);
        self::assertSame([
            'type' => 'package', 'width' => 30, 'depth' => 40, 'height' => 20, 'weight' => 5.0, 'value' => 100.0, 'description' => 'Ksiazki',
        ], $parcel->toArray());
    }

    public function testParcelWritesTheTypeDependentFields(): void
    {
        self::assertSame(['type' => 'furniture', 'quantity' => 2], (new Parcel(ParcelType::Furniture, quantity: 2))->toArray());
        self::assertSame(['type' => 'vehicles', 'brand' => 'Opel', 'model' => 'Corsa'], (new Parcel(ParcelType::Vehicles, brand: 'Opel', model: 'Corsa'))->toArray());
    }

    public function testCodWritesOnlyWhatWasGiven(): void
    {
        self::assertSame(['amount' => 120.0], (new Cod(120.0))->toArray());
        self::assertSame(
            ['amount' => 120.0, 'currency' => 'PLN', 'express' => false, 'iban' => 'PL1', 'name' => 'Jan', 'swift' => 'BREXPLPW'],
            (new Cod(120.0, 'PLN', false, 'PL1', 'Jan', 'BREXPLPW'))->toArray(),
        );
    }

    public function testPayerWritesTheCostCentre(): void
    {
        self::assertSame(['cost_center' => 'MPK-7'], (new Payer('MPK-7'))->toArray());
        self::assertSame([], (new Payer())->toArray());
    }

    public function testExportItemWritesTheDocumentedAndTheDictionaryFields(): void
    {
        $item = new ExportItem('Printed books', 'Printed books', 'Paper and card', 'Paper and card', 'For reading', 'For reading', 2, 49.9, 'PL', 'FV/1', 0.5, '4901990000', 'MID1');

        self::assertSame([
            'name_material' => 'Printed books', 'name_material_en' => 'Printed books',
            'item_composition' => 'Paper and card', 'item_composition_en' => 'Paper and card',
            'item_purpose' => 'For reading', 'item_purpose_en' => 'For reading',
            'items_count' => 2, 'item_price' => 49.9, 'country_code' => 'PL', 'invoice_no' => 'FV/1',
            'item_weight' => 0.5, 'tariff' => '4901990000', 'mid' => 'MID1',
        ], $item->toArray());
    }

    public function testDutyWritesEnumsAsTheirValuesAndSkipsEmptyLists(): void
    {
        $duty = new Duty([new ExportItem(nameMaterial: 'Books')], DutyType::Sale, DutyDocumentsOwner::Client, ['8b881c52-6473-467a-a7c9-d0aa4c4f3bec'], 12.5, 'PL1234567890123', null, 'PL1234567890');

        self::assertSame([
            'content' => [['name_material' => 'Books']],
            'type' => 'sale',
            'documents_owner' => 'client',
            'documents_uuids' => ['8b881c52-6473-467a-a7c9-d0aa4c4f3bec'],
            'transport_price' => 12.5,
            'eori' => 'PL1234567890123',
            'vat_number' => 'PL1234567890',
        ], $duty->toArray());
        self::assertSame([], (new Duty())->toArray());
    }

    public function testPickupDateAcceptsAStringOrADate(): void
    {
        self::assertSame('2026-10-02', (new PickupDate('2026-10-02'))->date);
        self::assertSame('2026-10-02', (new PickupDate(new \DateTimeImmutable('2026-10-02 15:30:00')))->date);
    }

    public function testPickupDateWritesTheProposalItWasTakenFrom(): void
    {
        $date = new PickupDate('2026-10-02', '08:00', '16:00', 'dfe1cc49bec1d942c1db625a8d710d45');

        self::assertSame(['date' => '2026-10-02', 'min_time' => '08:00', 'max_time' => '16:00', 'hash' => 'dfe1cc49bec1d942c1db625a8d710d45'], $date->toArray());
        self::assertSame(['date' => '2026-10-02'], (new PickupDate('2026-10-02'))->toArray());
    }

    public function testPickupDateNeedsADay(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PickupDate('');
    }

    public function testLabelOptionsFactories(): void
    {
        self::assertSame(['file_format' => 'pdf', 'page_format' => 'a6'], LabelOptions::pdf(LabelPageFormat::A6)->toArray());
        self::assertSame(['file_format' => 'pdf'], LabelOptions::pdf()->toArray());
        self::assertSame(['file_format' => 'pdf', 'page_format' => 'a4', 'add_cutting_line' => true], LabelOptions::pdf(LabelPageFormat::A4, true)->toArray());
        self::assertSame(['file_format' => 'zpl'], LabelOptions::zpl()->toArray());
        self::assertSame(['file_format' => 'epl'], LabelOptions::epl()->toArray());
        self::assertSame([], (new LabelOptions())->toArray(), 'Nothing set: the account settings decide.');
        self::assertSame(LabelFileFormat::Zpl, LabelOptions::zpl()->fileFormat);
    }

    // -- PriceScope ---------------------------------------------------------------

    public function testPriceScopeByCarrierMembersAndStrings(): void
    {
        $scope = PriceScope::carriers(Carrier::Dpd, 'novapost', Carrier::InPost);

        self::assertSame(['service' => ['dpd', 'novapost', 'inpost']], $scope->toArray());
        self::assertSame(['dpd', 'novapost', 'inpost'], $scope->carriers);
    }

    public function testPriceScopeByServiceId(): void
    {
        self::assertSame(['service_id' => [12014776, 12014781]], PriceScope::serviceIds(12014776, 12014781)->toArray());
    }

    public function testPriceScopeMayCombineBoth(): void
    {
        self::assertSame(
            ['service' => ['dpd'], 'service_id' => [5]],
            (new PriceScope([Carrier::Dpd], [5]))->toArray(),
        );
    }

    public function testPriceScopeDropsDuplicateCarriers(): void
    {
        self::assertSame(['service' => ['dpd']], PriceScope::carriers(Carrier::Dpd, 'dpd')->toArray());
    }

    /**
     * @return iterable<string, array{callable(): PriceScope, string}>
     */
    public static function badScopes(): iterable
    {
        yield 'nothing at all' => [static fn (): PriceScope => new PriceScope(), 'at least one carrier or one service id'];
        yield 'no carriers' => [static fn (): PriceScope => PriceScope::carriers(), 'at least one carrier or one service id'];
        yield 'no ids' => [static fn (): PriceScope => PriceScope::serviceIds(), 'at least one carrier or one service id'];
        yield 'a carrier with a space' => [static fn (): PriceScope => PriceScope::carriers('d p d'), 'an identifier such as "dpd"'];
        yield 'a blank carrier' => [static fn (): PriceScope => PriceScope::carriers(' '), 'an identifier such as "dpd"'];
        yield 'a carrier that is a path' => [static fn (): PriceScope => PriceScope::carriers('../dpd'), 'an identifier such as "dpd"'];
        yield 'a carrier with a newline in it' => [static fn (): PriceScope => PriceScope::carriers("dp\nd"), 'an identifier such as "dpd"'];
        yield 'a zero service id' => [static fn (): PriceScope => PriceScope::serviceIds(0), 'positive integer'];
        yield 'a negative service id' => [static fn (): PriceScope => PriceScope::serviceIds(5, -1), 'positive integer'];
    }

    /**
     * @param callable(): PriceScope $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badScopes')]
    public function testPriceScopeRefusesWhatFurgonetkaCannotPrice(callable $build, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $build();
    }
}
