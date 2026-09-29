<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\Cod;
use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\ExportItem;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\ErrorMapper;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RequestBuilder;
use VeryCodeCom\FurgonetkaApi\Internal\Validator\ShipmentValidator;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * The local validator against the API it stands in for: `POST /packages/validate` on the sandbox.
 *
 * The validator's contract is "an error is a rule the API enforces too, a warning is a rule that
 * is documented but that the API accepts a violation of". This test is what keeps that true. It
 * fails when Furgonetka starts enforcing a rule the validator only warns about, or stops
 * enforcing one it raises an error for, and then the severity table in the validator is to change.
 *
 * It also runs the request DTOs through the request builder to the real API, so the JSON they
 * write is checked against the endpoint, not only against the specification.
 *
 * About 35 validation requests and one login per run.
 */
final class ShipmentValidationTest extends SandboxTestCase
{
    private static ?string $token = null;

    /** @var array<string, array{id: int, owner: AgreementOwner|null}> service key => contract */
    private static array $services = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$token === null) {
            self::$token = $this->sharedAccessToken();

            $response = $this->transport()->send((new RequestBuilder($this->config()))->build(Endpoint::ListServices, accessToken: self::$token));
            /** @var array{services?: list<array{id?: int, service?: string, owner?: string}>} $data */
            $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

            foreach ($data['services'] ?? [] as $service) {
                if (isset($service['id'], $service['service'])) {
                    self::$services[$service['service']] = ['id' => $service['id'], 'owner' => AgreementOwner::tryFrom($service['owner'] ?? '')];
                }
            }
        }
    }

    /**
     * What the API says about a shipment.
     *
     * @return array{int, list<string>} HTTP status and the paths of the errors
     */
    private function askTheApi(ShipmentRequest $shipment): array
    {
        $request = (new RequestBuilder($this->config()))->build(Endpoint::ValidatePackage, body: $shipment->toArray(), accessToken: self::$token);
        $response = $this->transport()->send($request);

        if ($response->isSuccess()) {
            return [$response->statusCode, []];
        }

        $paths = [];
        foreach ((new ErrorMapper())->map($request, $response)->errors as $error) {
            $paths[] = (string) $error->path;
        }

        return [$response->statusCode, $paths];
    }

    private function contract(Carrier $carrier): int
    {
        $service = self::$services[$carrier->value] ?? null;

        if ($service === null) {
            self::markTestSkipped("The sandbox account has no {$carrier->value} service.");
        }

        return $service['id'];
    }

    private function owner(Carrier $carrier): ?AgreementOwner
    {
        return self::$services[$carrier->value]['owner'] ?? null;
    }

    /**
     * @param callable(int): ShipmentRequest $build
     * @return array{list<string>, list<string>, int, list<string>} local errors, local warnings, HTTP status, API error paths
     */
    private function compare(Carrier $carrier, callable $build): array
    {
        $shipment = $build($this->contract($carrier));
        $local = (new ShipmentValidator())->check($shipment, $carrier, $this->owner($carrier));
        [$status, $paths] = $this->askTheApi($shipment);

        return [$local['errors'], $local['warnings'], $status, $paths];
    }

    private static function gb(): \VeryCodeCom\FurgonetkaApi\Dto\Receiver
    {
        return new \VeryCodeCom\FurgonetkaApi\Dto\Receiver('Anna Nowak', null, 'Downing Street 10', 'SW1A 1AA', 'London', 'GB', null, 'anna@example.com', '+447911123456');
    }

    private static function item(): ExportItem
    {
        return new ExportItem('Printed paperback books', 'Printed paperback books', 'Paper and card board', 'Paper and card board', 'Reading by a private person', 'Reading by a private person', 1, 100.0, 'PL', 'FV/1/2026', 1.0, '4901990000');
    }

    // -- The shipment the DTOs build --------------------------------------------------

    public function testTheMinimalShipmentIsAcceptedByTheApi(): void
    {
        [$errors, $warnings, $status] = $this->compare(Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id));

        self::assertSame([], $errors);
        self::assertSame([], $warnings);
        self::assertSame(204, $status);
    }

    public function testAShipmentWithCustomsDataIsAcceptedByTheApi(): void
    {
        // The full set of export item fields, and a gift, which needs no EORI (a sale to the same country also needs a VAT number and an invoice number).
        [$errors, , $status, $paths] = $this->compare(Carrier::Ups, fn (int $id): ShipmentRequest => ShipmentFactory::request(
            $id,
            ShipmentFactory::pickup(),
            self::gb(),
            [ShipmentFactory::parcel()],
            duty: new Duty([self::item()], DutyType::Gift),
        ));

        self::assertSame([], $errors);
        self::assertSame(204, $status, 'The API objected to: ' . implode(', ', $paths));
    }

    // -- Every error the validator raises is an error of the API -------------------------

    /**
     * @return iterable<string, array{Carrier, callable(int): ShipmentRequest, string, string}> carrier, the shipment, the field path of the API, what the local message starts with
     */
    public static function errorsTheApiAgreesWith(): iterable
    {
        yield 'a name of 3 characters' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: 'A B')), '/receiver/name', '/receiver/name'];
        yield 'one word for a name' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: 'Kowalski')), '/receiver/name', '/receiver/name'];
        yield 'DHL receiver name of 61' => [Carrier::Dhl, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: 'Jan ' . str_repeat('K', 57))), '/receiver/name', '/receiver/name'];
        yield 'FedEx company of 151' => [Carrier::Fedex, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: null, company: str_repeat('K', 151))), '/receiver/company', '/receiver/company'];
        yield 'a receiver without a street' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(street: null)), '/receiver/street', '/receiver/street'];
        yield 'no parcels' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: []), '/parcels', '/parcels'];
        yield 'a DHL description of 31' => [Carrier::Dhl, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(description: str_repeat('x', 31))]), '/parcels/0/description', '/parcels/0/description'];
        yield 'DPD with 16 parcels' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: array_fill(0, 16, ShipmentFactory::parcel())), '/parcels/0', '/parcels'];
        yield 'UPS with 2 parcels' => [Carrier::Ups, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(), ShipmentFactory::parcel()]), '/parcels/0', '/parcels'];
        yield 'Poczta with 11 parcels' => [Carrier::Poczta, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: array_fill(0, 11, ShipmentFactory::parcel())), '/parcels/0', '/parcels'];
        yield 'DHL with 6 pallets' => [Carrier::Dhl, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: array_fill(0, 6, ShipmentFactory::parcel(ParcelType::Pallet))), '/parcels/0', '/parcels'];
        yield 'a COD above the value at DHL' => [Carrier::Dhl, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, services: new AdditionalServices(cod: new Cod(500.0, name: 'Jan Kowalski', iban: 'PL61109010140000071219812874'))), '/additional_services/cod/amount', '/additional_services/cod/amount'];
        yield 'a COD above the value at Poczta' => [Carrier::Poczta, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, services: new AdditionalServices(cod: new Cod(500.0, name: 'Jan Kowalski', iban: 'PL61109010140000071219812874'))), '/additional_services/cod/amount', '/additional_services/cod/amount'];
        yield 'Pocztex together with Kurier48' => [Carrier::Poczta, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, services: new AdditionalServices(pocztex: true, pocztaKurier48: true)), '/additional_services/pocztex', '/additional_services'];
        yield 'cud together with rod' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, services: new AdditionalServices(rod: true, cud: true)), '/additional_services/rod', '/additional_services/cud'];
        yield 'Great Britain without duty' => [Carrier::Ups, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: self::gb()), '/duty', '/duty'];
        yield 'a sale without EORI' => [Carrier::Ups, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: self::gb(), duty: new Duty([self::item()], DutyType::Sale)), '/duty/eori', '/duty/eori'];
        yield 'the United States without a state' => [Carrier::Ups, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(country: 'US')), '/receiver/county', '/receiver/county'];
        yield 'a DPD reference of 37' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, reference: str_repeat('a', 37)), '/user_reference_number', '/user_reference_number'];
    }

    /**
     * @param callable(int): ShipmentRequest $build
     */
    #[DataProvider('errorsTheApiAgreesWith')]
    public function testEveryLocalErrorIsAnErrorOfTheApiToo(Carrier $carrier, callable $build, string $apiPath, string $localPath): void
    {
        [$errors, , $status, $paths] = $this->compare($carrier, $build);

        $local = array_filter($errors, static fn (string $message): bool => str_starts_with($message, $localPath));

        self::assertNotSame([], $local, "The validator raised no error for {$localPath}: " . json_encode($errors));
        self::assertSame(400, $status);
        self::assertContains($apiPath, $paths, 'The API refused the shipment, but not for this field: ' . implode(', ', $paths));
    }

    // -- Every warning is a rule the API accepts a violation of --------------------------

    /**
     * @return iterable<string, array{Carrier, callable(int): ShipmentRequest, string}> carrier, the shipment, what the warning starts with
     */
    public static function warningsTheApiAccepts(): iterable
    {
        yield 'DHL without a description' => [Carrier::Dhl, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(description: null)]), '/parcels/0/description'];
        yield 'FedEx without a description' => [Carrier::Fedex, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(description: null)]), '/parcels/0/description'];
        yield 'Poczta without a description' => [Carrier::Poczta, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(description: null)]), '/parcels/0/description'];
        yield 'a DPD description of 101' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(description: str_repeat('x', 101))]), '/parcels/0/description'];
        yield 'a DPD reference of 28' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, reference: str_repeat('a', 28)), '/user_reference_number'];
        yield 'a FedEx reference of 31' => [Carrier::Fedex, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, reference: str_repeat('a', 31)), '/user_reference_number'];
        yield 'a GLS reference of 26' => [Carrier::Gls, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, reference: str_repeat('a', 26)), '/user_reference_number'];
        yield 'two Poczta parcels' => [Carrier::Poczta, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, parcels: [ShipmentFactory::parcel(), ShipmentFactory::parcel()]), '/parcels'];
        yield 'a DPD COD above the value' => [Carrier::Dpd, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, services: new AdditionalServices(cod: new Cod(500.0, name: 'Jan Kowalski', iban: 'PL61109010140000071219812874'))), '/additional_services/cod/amount'];
        yield 'a UPS receiver name of 31' => [Carrier::Ups, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: 'Jan ' . str_repeat('K', 27))), '/receiver/name'];
        yield 'a Poczta receiver company of 31' => [Carrier::Poczta, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: null, company: str_repeat('K', 31))), '/receiver/company'];
        yield 'a UPS receiver company of 31' => [Carrier::Ups, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id, receiver: ShipmentFactory::receiver(name: null, company: str_repeat('K', 31))), '/receiver/company'];
    }

    /**
     * @param callable(int): ShipmentRequest $build
     */
    #[DataProvider('warningsTheApiAccepts')]
    public function testEveryLocalWarningIsAShipmentTheApiAccepts(Carrier $carrier, callable $build, string $warningStart): void
    {
        [$errors, $warnings, $status, $paths] = $this->compare($carrier, $build);

        self::assertSame([], $errors);
        self::assertNotSame([], array_filter($warnings, static fn (string $message): bool => str_starts_with($message, $warningStart)), 'No warning for ' . $warningStart);
        self::assertSame(204, $status, 'The API now refuses what the validator only warns about (' . implode(', ', $paths) . '): make it an error.');
    }

    // -- Duty follows the destination, not the carrier (W17) -----------------------------

    /**
     * @return iterable<string, array{Carrier}>
     */
    public static function carriersTheSpecificationNamesForDuty(): iterable
    {
        yield 'UPS' => [Carrier::Ups];
        yield 'FedEx' => [Carrier::Fedex];
        yield 'GLS' => [Carrier::Gls];
    }

    #[DataProvider('carriersTheSpecificationNamesForDuty')]
    public function testADomesticShipmentNeedsNoDutyEvenForTheCarriersTheSpecificationNames(Carrier $carrier): void
    {
        [$errors, $warnings, $status] = $this->compare($carrier, static fn (int $id): ShipmentRequest => ShipmentFactory::request($id));

        self::assertSame([], $errors);
        self::assertSame([], $warnings);
        self::assertSame(204, $status);
    }
}
