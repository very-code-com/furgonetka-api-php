<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\Duty;
use VeryCodeCom\FurgonetkaApi\Dto\ExportItem;
use VeryCodeCom\FurgonetkaApi\Dto\Receiver;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * Shipments that leave Poland, against the real sandbox: to Germany with DPD from the request to the label, to the
 * United Kingdom with UPS with a customs declaration (created, read back and deleted), and to Norway with UPS, where an
 * order is refused until the sender has signed the customs documents in the panel.
 *
 * The international DPD service has regulations of its own (`dpd_international`), separate from the domestic ones, so
 * a shop that ordered at home for months meets a 409 on its first shipment to Germany.
 *
 * The ordering test spends sandbox balance (it is refunded when the shipment is cancelled at the end); set
 * `FURGONETKA_ALLOW_ORDERS=0` to leave it out.
 */
final class InternationalShipmentTest extends SandboxTestCase
{
    private const REFERENCE = 'International test';

    private static ?FurgonetkaApiClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$client === null) {
            return;
        }

        try {
            foreach (self::$client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Ordered)) as $package) {
                if ($package->userReferenceNumber === self::REFERENCE && $package->isOrdered()) {
                    self::$client->cancelPackages([$package]);
                }
            }

            $ids = [];

            foreach (self::$client->listPackages(new PackageListQuery(limit: 50, listType: ListType::Waiting)) as $package) {
                if ($package->userReferenceNumber === self::REFERENCE && $package->id !== null) {
                    $ids[] = $package->id;
                }
            }

            if ($ids !== []) {
                self::$client->deletePackages($ids);
            }
        } catch (\Throwable) {
            // Best effort: the sandbox is disposable.
        }
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    private function serviceId(Carrier $carrier): int
    {
        $service = $this->client()->listServices()->first($carrier);
        self::assertNotNull($service, 'The sandbox account has no ' . $carrier->value . ' contract.');

        return $service->id ?? 0;
    }

    private function toGermany(): ShipmentRequest
    {
        return ShipmentFactory::request(
            $this->serviceId(Carrier::Dpd),
            receiver: new Receiver('Hans Mueller', null, 'Unter den Linden 1', '10117', 'Berlin', 'DE', null, 'hans@example.com', '+491701234567'),
            reference: self::REFERENCE,
        );
    }

    private function toTheUnitedKingdom(?Duty $duty): ShipmentRequest
    {
        return ShipmentFactory::request(
            $this->serviceId(Carrier::Ups),
            receiver: new Receiver('Anna Nowak', null, 'Downing Street 10', 'SW1A 1AA', 'London', 'GB', null, 'anna@example.com', '+447911123456'),
            duty: $duty,
            reference: self::REFERENCE,
        );
    }

    private static function gift(): Duty
    {
        return new Duty([new ExportItem('Printed paperback books', 'Printed paperback books', 'Paper and card board', 'Paper and card board', 'Reading by a private person', 'Reading by a private person', 1, 100.0, 'PL', 'FV/1/2026', 5.0, '4901990000')], DutyType::Gift);
    }

    public function testAShipmentToGermanyIsCreatedAndItsReceiverKeepsTheCountry(): void
    {
        $package = $this->client()->createPackage($this->toGermany());

        try {
            $read = $this->client()->fetchPackage($package);

            self::assertSame('DE', $read->receiver?->countryCode);
            self::assertSame('PL', $read->pickup?->countryCode);
            self::assertSame(Carrier::Dpd, $read->carrier);
            self::assertSame('Berlin', $read->receiver->city);
        } finally {
            $this->client()->deletePackage($package);
        }
    }

    public function testTheInternationalServiceOfDpdHasRegulationsOfItsOwn(): void
    {
        $package = $this->client()->createPackage($this->toGermany());
        $domestic = $this->client()->createPackage(ShipmentFactory::request($this->serviceId(Carrier::Dpd), reference: self::REFERENCE));

        try {
            $abroad = array_map(static fn ($r): string => (string) $r->service, $this->client()->pendingRegulations([$package]));
            $athome = array_map(static fn ($r): string => (string) $r->service, $this->client()->pendingRegulations([$domestic]));

            self::assertSame([], array_diff($abroad, ['dpd_international']), 'Only the international regulation can stand in the way of a shipment to Germany.');
            self::assertNotContains('dpd_international', $athome, 'A domestic shipment does not need it.');
            self::assertNotContains('dpd_international', $this->client()->regulationsForPackages([$domestic]));
        } finally {
            $this->client()->deletePackages([$package, $domestic]);
        }
    }

    public function testAShipmentToGermanyIsOrderedLabelledAndCancelled(): void
    {
        if ($this->env('FURGONETKA_ALLOW_ORDERS') === '0') {
            self::markTestSkipped('FURGONETKA_ALLOW_ORDERS=0 keeps the run read-only.');
        }

        $client = $this->client();
        $package = $client->createPackage($this->toGermany());

        // Accepted explicitly, on this disposable account, as the lifecycle test does for the domestic DPD terms.
        $pending = $client->pendingRegulations([$package]);

        if ($pending !== []) {
            $client->acceptRegulations($pending);
        }

        self::assertSame([], $client->pendingRegulations([$package]));

        $result = $client->orderPackages([$package]);

        self::assertTrue($result->isSuccessful(), implode('; ', array_map('strval', $result->errors)));

        $ordered = $client->fetchPackage($package);
        self::assertNotSame([], $ordered->waybillNumbers());
        self::assertTrue($client->fetchLabel($package)->isPdf());
        self::assertSame('DE', $ordered->receiver?->countryCode);

        self::assertTrue($client->cancelPackages([$package])->isSuccessful());
    }

    public function testACustomsDeclarationToTheUnitedKingdomIsAcceptedAndKeptWithTheShipment(): void
    {
        $package = $this->client()->createPackage($this->toTheUnitedKingdom(self::gift()));

        try {
            $read = $this->client()->fetchPackage($package);

            self::assertSame('GB', $read->receiver?->countryCode);
            self::assertSame(Carrier::Ups, $read->carrier);
            self::assertArrayHasKey('duty', $read->raw(), 'The answer carries the customs declaration.');
        } finally {
            $this->client()->deletePackage($package);
        }
    }

    public function testTheUnitedKingdomWithoutACustomsDeclarationIsRefusedBeforeARequest(): void
    {
        try {
            $this->client()->createPackage($this->toTheUnitedKingdom(null));
            self::fail('The local rules must refuse it.');
        } catch (FurgonetkaApiValidationException $e) {
            self::assertStringContainsString('/duty', implode(' ', $e->errors));
        }

        $refused = $this->client()->validateRemotely($this->toTheUnitedKingdom(null));

        self::assertSame(['/duty'], array_map(static fn ($error): ?string => $error->path, $refused), 'Furgonetka refuses it too, on the same field.');
    }

    public function testAnOrderOutsideTheEuIsRefusedUntilTheCustomsDocumentsAreSigned(): void
    {
        if ($this->env('FURGONETKA_ALLOW_ORDERS') === '0') {
            self::markTestSkipped('FURGONETKA_ALLOW_ORDERS=0 keeps the run read-only.');
        }

        $client = $this->client();
        $package = $client->createPackage(ShipmentFactory::request(
            $this->serviceId(Carrier::Ups),
            receiver: new Receiver('Ola Nordmann', null, 'Karl Johans gate 1', '0154', 'Oslo', 'NO', null, 'ola@example.com', '+4791234567'),
            duty: self::gift(),
            reference: self::REFERENCE,
        ));

        try {
            if ($client->pendingRegulations([$package]) !== []) {
                self::markTestSkipped('The UPS regulations are not accepted on this account, so the order would be a 409 first.');
            }

            $read = $client->fetchPackage($package);
            self::assertTrue($read->signatureRequired(), 'The answer says the customs documents need the sender\'s signature.');
            self::assertFalse($read->documentsSigned());

            // The signature is a drawn one on a page of the panel; the API has no way to give it, and refuses before charging anything.
            try {
                $client->orderPackages([$package]);
                self::fail('Expected a 400.');
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('noSignature'));
                self::assertContains('/packages/0/id', $e->getFieldPaths());
            }
        } finally {
            $client->deletePackage($package);
        }
    }

    public function testEveryCountryThatNeedsCustomsDataIsKnownToTheCountryList(): void
    {
        $countries = $this->client()->fetchAllowedCountries();

        foreach (['GB', 'CH', 'NO'] as $code) {
            self::assertTrue($countries->requiresDuty($code), $code . ' needs customs data.');
        }

        foreach (['DE', 'FR', 'CZ'] as $code) {
            self::assertFalse($countries->requiresDuty($code), $code . ' is inside the EU.');
        }
    }
}
