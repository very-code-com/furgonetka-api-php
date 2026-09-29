<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingReceiver;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;

/**
 * Returns and readdressing against the real sandbox: the errors, which are the only answers it
 * gave. Every ordered shipment it was asked about, freshly ordered and with any simulated state,
 * was "not possible" (`notPossible`), so a price or an accepted order has not been seen here and
 * the success shapes are covered by unit tests built from the specification (open check W27).
 *
 * Nothing here orders, returns or readdresses anything, and nothing is created.
 */
final class ReturnsAndReaddressingTest extends SandboxTestCase
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

    private static function receiver(): ReaddressingReceiver
    {
        return new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '500200300', name: 'Piotr Zielinski', email: 'piotr@example.com');
    }

    /**
     * The id of the oldest starter DPD shipment, or a skip when the account has none. Not the newest:
     * `TrackingTest` simulates tracking states on that one, and the sandbox answered a readdressing
     * price for a simulated `transit` shipment with a price calculation error instead of `notPossible`,
     * so its answer would depend on the order the classes ran in.
     */
    private function starterDpdId(): string
    {
        foreach (array_reverse($this->starterShipmentsOf($this->client())) as $package) {
            if ($package->carrier === Carrier::Dpd && $package->id !== null) {
                return $package->id;
            }
        }

        self::markTestSkipped('The sandbox account has no starter DPD shipment.');
    }

    public function testAShipmentThatDoesNotExistIsRefusedByEveryOperation(): void
    {
        $client = $this->client();

        foreach ([
            'return price' => static fn () => $client->calculateReturnPrice('999999999'),
            'return' => static fn () => $client->returnPackage('999999999'),
            'readdressing price' => static fn () => $client->calculateReaddressingPrice('999999999', self::receiver()),
        ] as $name => $call) {
            try {
                $call();
                self::fail("{$name}: expected a FurgonetkaApiBadRequestException.");
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('packageDoNotExist'), "{$name}: {$e->getMessage()}");
                self::assertSame(['/packageId'], $e->getFieldPaths(), $name);
            }
        }
    }

    public function testAStarterShipmentCannotBeReaddressed(): void
    {
        $id = $this->starterDpdId();

        foreach ([
            'price' => fn () => $this->client()->calculateReaddressingPrice($id, self::receiver()),
            'order' => fn () => $this->client()->readdressPackage($id, self::receiver(), Carrier::Dpd),
        ] as $name => $call) {
            try {
                $call();
                self::fail("{$name}: expected a FurgonetkaApiBadRequestException.");
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('notPossible'), "{$name}: {$e->getMessage()}");
                self::assertStringContainsString('cannot be readdressed', $e->getMessage());
            }
        }
    }

    public function testAReaddressingTheAccountDoesNotKnowIsA400(): void
    {
        foreach ([
            fn () => $this->client()->fetchReaddressing(Uuid::v4()),
            fn () => $this->client()->cancelReaddressing(Uuid::v4()),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a FurgonetkaApiBadRequestException.');
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('commandNotExists'));
                self::assertSame(['/uuid'], $e->getFieldPaths());
            }
        }
    }
}
