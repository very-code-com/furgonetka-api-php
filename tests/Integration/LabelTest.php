<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiLabelNotReadyException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;

/**
 * The data printed on the label (`sender`, plan W6) and what the label endpoint answers for shipments
 * that have none. A 410 was never seen (W9): every case below is a 404 or a 204.
 */
final class LabelTest extends SandboxTestCase
{
    private static ?FurgonetkaApiClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    private function dpdServiceId(): int
    {
        $dpd = $this->client()->listServices()->first(Carrier::Dpd);
        self::assertNotNull($dpd, 'The sandbox account has no DPD contract.');

        return $dpd->id ?? 0;
    }

    public function testASenderIsStoredAndReadBackWithoutAnyAccountOption(): void
    {
        $serviceId = $this->dpdServiceId();
        $ids = [];

        try {
            $plain = $this->client()->createPackage(ShipmentFactory::request($serviceId, reference: 'Label test plain'));
            $ids[] = (string) $plain->id;
            $with = $this->client()->createPackage(ShipmentFactory::request($serviceId, reference: 'Label test sender', labelSender: ShipmentFactory::labelSender(name: 'Label Sender', company: 'Label Co', street: 'Marszalkowska 10', postcode: '00-590')));
            $ids[] = (string) $with->id;

            $read = $this->client()->fetchPackage((string) $with->id);
            self::assertSame('Label Sender', $read->labelSender?->name);
            self::assertSame('Label Co', $read->labelSender->company);
            self::assertSame('Marszalkowska 10', $read->labelSender->street);
            self::assertSame('00-590', $read->labelSender->postcode);
            self::assertFalse($read->raw()['sender']['only_pickup_provided'] ?? null, 'The label data differ from the pickup address.');

            self::assertNull($this->client()->fetchPackage((string) $plain->id)->labelSender, 'Without one, `sender` is null.');
        } finally {
            foreach ($ids as $id) {
                $this->client()->deletePackage($id);
            }
        }
    }

    public function testASenderNeedsANameOrACompany(): void
    {
        $errors = $this->client()->validateRemotely(ShipmentFactory::request($this->dpdServiceId(), labelSender: ShipmentFactory::labelSender(name: null, company: null)));

        self::assertSame(['/sender/name', '/sender/company'], array_map(static fn ($e): ?string => $e->path, $errors));
        self::assertSame(['isEmpty', 'isEmpty'], array_map(static fn ($e): ?string => $e->code, $errors));
    }

    public function testAPolishSenderTakesNoRegionOfTheFormAPolishVoivodeshipIsWrittenIn(): void
    {
        $errors = $this->client()->validateRemotely(ShipmentFactory::request($this->dpdServiceId(), labelSender: ShipmentFactory::labelSender(county: 'mazowieckie')));

        self::assertSame(['/sender/county'], array_map(static fn ($e): ?string => $e->path, $errors));
        self::assertSame('invalidRegion', $errors[0]->code);
    }

    public function testTheLabelOfACancelledShipmentIsA404OnThePackageId(): void
    {
        $cancelled = null;

        foreach ($this->client()->listPackages(new PackageListQuery(limit: 50, listType: ListType::Ordered)) as $package) {
            if ($package->stateValue === 'cancelled') {
                $cancelled = $package;
                break;
            }
        }

        if ($cancelled === null) {
            self::markTestSkipped('The sandbox account has no cancelled shipment.');
        }

        try {
            $this->client()->fetchLabel($cancelled);
            self::fail('A cancelled shipment has no label.');
        } catch (FurgonetkaApiNotFoundException $e) {
            self::assertSame(['/package_id'], $e->getFieldPaths());
            self::assertStringContainsString('Package cancelled', $e->getMessage());
        }
    }

    public function testAShipmentThatDoesNotExistIsA404OnThePackageId(): void
    {
        try {
            $this->client()->fetchLabel('999999999');
            self::fail('There is no such shipment.');
        } catch (FurgonetkaApiNotFoundException $e) {
            self::assertSame(['/package_id'], $e->getFieldPaths());
        }
    }

    public function testATrackedPackageHasNoLabelAndAnswersLikeOneThatIsNotReady(): void
    {
        $tracked = null;

        foreach ($this->client()->listPackages(new PackageListQuery(limit: 50, listType: ListType::Tracking)) as $package) {
            $tracked = $package;
            break;
        }

        if ($tracked === null) {
            self::markTestSkipped('The sandbox account tracks no package: run TrackingTest first.');
        }

        $this->expectException(FurgonetkaApiLabelNotReadyException::class);

        $this->client()->fetchLabel($tracked);
    }
}
