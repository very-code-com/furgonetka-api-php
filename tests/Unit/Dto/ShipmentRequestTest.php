<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\ExportItem;
use VeryCodeCom\FurgonetkaApi\Dto\Payer;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\DutyDocumentsOwner;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RequestBuilder;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class ShipmentRequestTest extends TestCase
{
    public function testTheMinimalShipmentIsThePayloadTheSandboxValidatedWith204(): void
    {
        // Sandbox, 2026-09-29: POST /packages/validate (v2) with exactly this body answered 204.
        $accepted = [
            'service_id' => 12014776,
            'pickup' => ['name' => 'Jan Kowalski', 'street' => 'Marszalkowska 100', 'postcode' => '00-026', 'city' => 'Warszawa', 'country_code' => 'PL', 'phone' => '500600700', 'email' => 'jan@example.com'],
            'receiver' => ['name' => 'Anna Nowak', 'street' => 'Florianska 15', 'postcode' => '31-021', 'city' => 'Krakow', 'country_code' => 'PL', 'phone' => '600700800', 'email' => 'anna@example.com'],
            'parcels' => [['type' => 'package', 'width' => 30, 'depth' => 40, 'height' => 20, 'weight' => 5.0, 'value' => 100, 'description' => 'Ksiazki']],
        ];

        self::assertEquals($accepted, ShipmentFactory::request()->toArray());
    }

    public function testEveryFieldIsWrittenWithItsApiName(): void
    {
        $request = new ShipmentRequest(
            serviceId: 7,
            pickup: ShipmentFactory::pickup(),
            receiver: ShipmentFactory::receiver(),
            parcels: [ShipmentFactory::parcel()],
            userReferenceNumber: 'Zamowienie 123',
            labelSender: ShipmentFactory::labelSender(),
            additionalServices: new AdditionalServices(cod: new Cod(50.0), rod: true),
            duty: new Duty([new ExportItem(nameMaterial: 'Books')], DutyType::Gift, DutyDocumentsOwner::Provided),
            payer: new Payer('MPK-1'),
            filesUuids: ['8b881c52-6473-467a-a7c9-d0aa4c4f3bec'],
            photos: ['a8bc8db0-8d8b-4f16-ac32-f69be9e40855'],
            extra: ['brand_new_field' => 'x'],
        );

        $data = $request->toArray();

        self::assertSame(
            ['service_id', 'pickup', 'receiver', 'user_reference_number', 'sender', 'additional_services', 'duty', 'payer', 'files_uuids', 'photos', 'parcels', 'brand_new_field'],
            array_keys($data),
        );
        self::assertSame('Piotr Zielinski', $data['sender']['name'], 'The label sender travels as `sender`.');
        self::assertSame(['cod' => ['amount' => 50.0], 'rod' => true], $data['additional_services']);
        self::assertSame('gift', $data['duty']['type']);
        self::assertSame(['cost_center' => 'MPK-1'], $data['payer']);
    }

    public function testTheTypedKeysListMatchesWhatAFullShipmentWrites(): void
    {
        $full = new ShipmentRequest(
            serviceId: 7,
            pickup: ShipmentFactory::pickup(),
            receiver: ShipmentFactory::receiver(),
            parcels: [ShipmentFactory::parcel()],
            userReferenceNumber: 'x',
            labelSender: ShipmentFactory::labelSender(),
            additionalServices: new AdditionalServices(rod: true),
            duty: new Duty([new ExportItem(nameMaterial: 'x')]),
            payer: new Payer('x'),
            filesUuids: ['x'],
            photos: ['x'],
        );

        $keys = array_keys($full->toArray());
        sort($keys);
        $expected = ShipmentRequest::KEYS;
        sort($expected);

        self::assertSame($expected, $keys);
    }

    public function testOptionalStructuresAndEmptyListsAreLeftOut(): void
    {
        $data = ShipmentFactory::request(services: new AdditionalServices())->toArray();

        self::assertSame(['service_id', 'pickup', 'receiver', 'parcels'], array_keys($data));
    }

    public function testParcelsAreAlwaysSentEvenWhenThereAreNone(): void
    {
        $data = ShipmentFactory::request(parcels: [])->toArray();

        self::assertSame([], $data['parcels']);
    }

    public function testExtraFieldsAreAddedAfterTheTypedOnesAndNullsAreDropped(): void
    {
        $request = new ShipmentRequest(
            serviceId: 7,
            pickup: ShipmentFactory::pickup(),
            receiver: ShipmentFactory::receiver(),
            parcels: [],
            extra: ['new_field' => ['a' => 1], 'nothing' => null, 'zero' => 0],
        );

        $data = $request->toArray();

        self::assertSame(['a' => 1], $data['new_field']);
        self::assertSame(0, $data['zero']);
        self::assertArrayNotHasKey('nothing', $data);
        self::assertSame('parcels', array_keys($data)[3], 'Typed fields come first.');
    }

    public function testAnExtraFieldThatIsATypedFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"service_id" is a typed field, use the parameter instead of $extra');

        new ShipmentRequest(1, ShipmentFactory::pickup(), ShipmentFactory::receiver(), [], extra: ['service_id' => 2]);
    }

    public function testTheLabelSenderCannotBeSmuggledInAsSender(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"sender" is a typed field');

        new ShipmentRequest(1, ShipmentFactory::pickup(), ShipmentFactory::receiver(), [], extra: ['sender' => ['name' => 'x']]);
    }

    public function testTheRequestBuilderSendsItAsAVersion2JsonBody(): void
    {
        $request = (new RequestBuilder(TokenFactory::config()))->build(
            Endpoint::CreatePackage,
            body: ShipmentFactory::request(reference: 'Zamowienie 123')->toArray(),
            accessToken: 'token',
        );

        $sent = json_decode((string) $request->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($sent);
        self::assertSame(12014776, $sent['service_id']);
        self::assertSame('Zamowienie 123', $sent['user_reference_number']);
        self::assertSame('application/vnd.furgonetka.v2+json', $request->header('content-type'));
    }
}
