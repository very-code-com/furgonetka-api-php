<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;

/**
 * How the sandbox picks the version of an answer from the media types of a request (plan W1 and W2):
 * `Accept` decides for a request without a body, and for one with a body the newer of `Accept` and
 * `Content-Type` wins. A number above the newest version is served as the newest, one that is not a
 * number or is below 2 as v1. The library sends the registry's version in both headers, so it does not
 * depend on the finer points, but the registry is only right while this holds.
 */
final class VersioningTest extends SandboxTestCase
{
    private const V1 = 'application/vnd.furgonetka.v1+json';
    private const V2 = 'application/vnd.furgonetka.v2+json';

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

    private function starterId(): string
    {
        $starters = $this->starterShipmentsOf($this->client());

        if ($starters === [] || $starters[0]->id === null) {
            self::markTestSkipped('The sandbox account has no starter shipment.');
        }

        return $starters[0]->id;
    }

    /**
     * The decoded answer to one request with the given media types.
     *
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $accept, ?array $body = null, ?string $contentType = null): array
    {
        $headers = ['Accept' => $accept, 'Authorization' => 'Bearer ' . $this->sharedAccessToken(), 'X-Language' => 'en_GB'];

        if ($body !== null) {
            $headers['Content-Type'] = $contentType ?? self::V1;
        }

        $response = $this->transport()->send(new TransportRequest('https://api.sandbox.furgonetka.pl' . $path, $method, $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null, $headers));

        self::assertTrue($response->isSuccess(), $method . ' ' . $path . ' answered ' . $response->statusCode);
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testAShipmentIsAnsweredInV1OrV2AccordingToAccept(): void
    {
        $path = '/packages/' . $this->starterId();

        self::assertArrayHasKey('type', $this->call('GET', $path, self::V1), 'v1 has the top-level "type".');
        self::assertArrayNotHasKey('type', $this->call('GET', $path, self::V2), 'v2 does not.');
    }

    public function testTrackingHasADifferentShapeInEachVersion(): void
    {
        $path = '/packages/' . $this->starterId() . '/tracking';

        self::assertSame(['tracking', 'additionalInfoTracking'], array_keys($this->call('GET', $path, self::V1)));
        self::assertSame(['parcels'], array_keys($this->call('GET', $path, self::V2)));
    }

    public function testNoVersionOrAnyMediaTypeButV2IsV1(): void
    {
        $path = '/packages/' . $this->starterId() . '/tracking';

        foreach (['application/json', '*/*', 'application/vnd.furgonetka.v0+json', 'application/vnd.furgonetka.vx+json', 'application/vnd.furgonetka.v2.5+json'] as $accept) {
            self::assertArrayHasKey('tracking', $this->call('GET', $path, $accept), $accept);
        }
    }

    public function testANumberAboveTheNewestVersionIsServedAsTheNewest(): void
    {
        $path = '/packages/' . $this->starterId() . '/tracking';

        foreach (['application/vnd.furgonetka.v3+json', 'application/vnd.furgonetka.v9+json'] as $accept) {
            self::assertArrayHasKey('parcels', $this->call('GET', $path, $accept), $accept);
        }
    }

    public function testTheListAndTheSingleShipmentFollowTheSameRule(): void
    {
        $v1 = $this->call('GET', '/packages?limit=1&list_type=ordered', self::V1);
        $v2 = $this->call('GET', '/packages?limit=1&list_type=ordered', self::V2);

        self::assertIsArray($v1['packages']);
        self::assertIsArray($v2['packages']);
        self::assertArrayHasKey('type', (array) $v1['packages'][0]);
        self::assertArrayNotHasKey('type', (array) $v2['packages'][0]);
    }

    public function testAnAnswerToARequestWithABodyTakesTheNewerOfAcceptAndContentType(): void
    {
        $dpd = $this->client()->listServices()->first(Carrier::Dpd);
        self::assertNotNull($dpd, 'The sandbox account has no DPD contract.');
        $body = ShipmentFactory::request($dpd->id ?? 0, reference: 'Versioning test')->toArray();
        $created = [];

        try {
            foreach ([[self::V2, self::V1, false], [self::V1, self::V2, false], [self::V2, self::V2, false], [self::V1, self::V1, true], ['application/json', 'application/json', true]] as [$contentType, $accept, $hasType]) {
                $answer = $this->call('POST', '/packages', $accept, $body, $contentType);
                $created[] = (string) $answer['package_id'];

                self::assertSame($hasType, array_key_exists('type', $answer), "Content-Type {$contentType}, Accept {$accept}");
            }
        } finally {
            foreach ($created as $id) {
                $this->client()->deletePackage($id);
            }
        }
    }

    public function testTheClientReadsTheV2Shape(): void
    {
        $package = $this->client()->fetchPackage($this->starterId());

        self::assertInstanceOf(Package::class, $package);
        self::assertArrayNotHasKey('type', $package->raw(), 'The registry asks for v2 and gets it.');
    }
}
