<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Dto\Coordinates;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookLog;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Tracking, tracked packages, the simulation, the points search and the webhook through the
 * client, on the bodies the sandbox gave.
 */
final class FurgonetkaApiClientTrackingTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';
    private const TOKEN = '0123456789abcde';

    // -- Tracking ---------------------------------------------------------------------------------

    public function testTheTrackingIsReadInVersionTwo(): void
    {
        $h = new ClientHarness([Fixtures::response('tracking/collected')]);

        $result = $h->client->fetchTracking('21353632');

        self::assertSame(['0000888274496Q'], $result->numbers());
        self::assertSame(['GET ' . self::HOST . '/packages/21353632/tracking'], $h->sent());
        self::assertSame('application/vnd.furgonetka.v2+json', $h->transport->requests[0]->header('Accept'));
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
    }

    public function testAPackageObjectOrANumberWorksAsTheId(): void
    {
        $h = new ClientHarness([Fixtures::response('tracking/ordered'), Fixtures::response('tracking/ordered')]);

        $h->client->fetchTracking(21353632);
        $h->client->fetchTracking(new Package(id: '21353633'));

        self::assertSame(['GET ' . self::HOST . '/packages/21353632/tracking', 'GET ' . self::HOST . '/packages/21353633/tracking'], $h->sent());
    }

    /** @return iterable<string, array{string|int|Package, string}> */
    public static function badTrackingIds(): iterable
    {
        yield 'a waybill number, which the endpoint answers 404 to' => ['0000888274496Q', 'not a waybill number'];
        yield 'an id with a newline inside' => ["1\n2", 'not a waybill number'];
        yield 'an empty id' => [' ', 'must not be empty'];
        yield 'a package without an id' => [new Package(), 'must not be empty'];
    }

    #[DataProvider('badTrackingIds')]
    public function testAnIdThatIsNotTheNumberFurgonetkaGaveIsRefusedBeforeARequest(string|int|Package $id, string $message): void
    {
        $h = new ClientHarness();

        try {
            $h->client->fetchTracking($id);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testATrackingThatFurgonetkaDoesNotKnowIsA404(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/not-found-package', 404)]);

        try {
            $h->client->fetchTracking('999999999');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiNotFoundException $e) {
            self::assertSame(['/package_id'], $e->getFieldPaths());
        }
    }

    // -- Tracked packages -------------------------------------------------------------------------

    public function testAPackageIsAddedToTracking(): void
    {
        $h = new ClientHarness([Fixtures::response('tracking/add-to-tracking')]);

        $tracked = $h->client->addToTracking(' Z1234567890L ', Carrier::Dpd, 'Probe tracked package');

        self::assertSame('21353630', $tracked->id);
        self::assertSame(Carrier::Dpd, $tracked->carrier);
        self::assertSame('POST ' . self::HOST . '/packages/add-to-tracking', $h->sent()[0]);
        self::assertSame(['package_no' => 'Z1234567890L', 'service' => 'dpd', 'name' => 'Probe tracked package'], $h->body());
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Content-Type'));
    }

    public function testTheCarrierAndTheNameAreLeftOutWhenNotGiven(): void
    {
        $h = new ClientHarness([Fixtures::response('tracking/add-to-tracking'), Fixtures::response('tracking/add-to-tracking')]);

        $h->client->addToTracking('Z1234567890L');
        $h->client->addToTracking('Z1234567890L', ' ', null);

        self::assertSame(['package_no' => 'Z1234567890L'], $h->body(0), 'Furgonetka tries to recognise the carrier.');
        self::assertSame(['package_no' => 'Z1234567890L'], $h->body(1), 'A blank carrier is no carrier.');
    }

    public function testACarrierGivenAsTextIsSentAsItIs(): void
    {
        $h = new ClientHarness([Fixtures::response('tracking/add-to-tracking')]);

        $h->client->addToTracking('Z1234567890L', ' swiatprzesylek ');

        self::assertSame('swiatprzesylek', $h->body()['service']);
    }

    public function testAnEmptyWaybillNumberIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        try {
            $h->client->addToTracking("  \n");
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must not be empty', $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testANumberThatIsAlreadyTrackedIsA400WithTheCodeExists(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-tracking-exists', 400)]);

        try {
            $h->client->addToTracking('Z1234567890L', Carrier::Dpd);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('exists'));
            self::assertSame(['/package_no'], $e->getFieldPaths());
        }
    }

    public function testANumberThatIsAmongTheOrderedShipmentsIsA400WithTheCodeExistsSent(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-tracking-exists-sent', 400)]);

        try {
            $h->client->addToTracking('0000888261798Q');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('existsSent'));
        }
    }

    public function testATrackedPackageIsRenamed(): void
    {
        $h = new ClientHarness([Fixtures::response('tracking/edit-tracked')]);

        $tracked = $h->client->renameTrackedPackage('21353630', 'Renamed probe');

        self::assertSame('Renamed probe', $tracked->name);
        self::assertSame('PUT ' . self::HOST . '/packages/edit-tracked-package/21353630', $h->sent()[0]);
        self::assertSame(['name' => 'Renamed probe'], $h->body());
    }

    public function testRenamingNeedsANameAndANumericId(): void
    {
        $h = new ClientHarness();

        foreach ([['21353630', ' '], ['Z1234567890L', 'name']] as [$id, $name]) {
            try {
                $h->client->renameTrackedPackage($id, $name);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $h->transport->requests);
    }

    // -- The simulation -----------------------------------------------------------------------------

    public function testAStateIsSimulatedWithBothTheIdAndTheWaybillNumber(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->simulateTracking('21353632', TrackingState::Transit, '0000888274496Q');

        self::assertSame('PUT ' . self::HOST . '/packages/tracking/simulate', $h->sent()[0]);
        self::assertSame(['packages_id' => 21353632, 'package_no' => '0000888274496Q', 'tracking_state' => 'transit'], $h->body());
        self::assertTrue($h->logger->has(LogLevel::INFO, 'simulating a tracking state'));
    }

    public function testAPackageObjectSuppliesItsFirstWaybillNumber(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);
        $package = Package::fromArray(Fixtures::json('packages/ordered'));

        $h->client->simulateTracking($package, TrackingState::Delivered);

        self::assertSame(['packages_id' => 21353373, 'package_no' => '0000888261798Q', 'tracking_state' => 'delivered'], $h->body());
    }

    public function testAWaybillNumberThatIsGivenBeatsTheOneOfThePackage(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->simulateTracking(Package::fromArray(Fixtures::json('packages/ordered')), TrackingState::Collected, ' 000777 ');

        self::assertSame('000777', $h->body()['package_no']);
    }

    public function testAnIdAloneIsNotEnough(): void
    {
        $h = new ClientHarness();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Give the waybill number too');

        try {
            $h->client->simulateTracking('21353632', TrackingState::Transit);
        } finally {
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testAPackageThatWasNotOrderedHasNoWaybillToSimulateOn(): void
    {
        $h = new ClientHarness();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order it first');

        try {
            $h->client->simulateTracking(Package::fromArray(Fixtures::json('packages/created')), TrackingState::Transit);
        } finally {
            self::assertSame([], $h->transport->requests);
        }
    }

    /** @return iterable<string, array{TrackingState}> */
    public static function statesTheApiRefuses(): iterable
    {
        yield 'ready-for-transport' => [TrackingState::ReadyForTransport];
        yield 'waiting-for-receiver' => [TrackingState::WaitingForReceiver];
    }

    #[DataProvider('statesTheApiRefuses')]
    public function testTwoStatesAreRefusedLocallyBecauseTheApiRefusesThem(TrackingState $state): void
    {
        $h = new ClientHarness();

        try {
            $h->client->simulateTracking('21353632', $state, '0000888274496Q');
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($state->value, $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testEveryOtherStateIsAllowed(): void
    {
        $allowed = array_filter(TrackingState::cases(), static fn (TrackingState $s): bool => $s !== TrackingState::ReadyForTransport && $s !== TrackingState::WaitingForReceiver);
        $h = new ClientHarness(array_fill(0, count($allowed), Fixtures::empty(204)));

        foreach ($allowed as $state) {
            $h->client->simulateTracking('1', $state, 'N1');
        }

        self::assertCount(11, $h->transport->requests, 'The eleven states of the specification.');
    }

    public function testTheSimulationDoesNotExistOnProduction(): void
    {
        $h = new ClientHarness(config: TokenFactory::config(sandbox: false));

        try {
            $h->client->simulateTracking('21353632', TrackingState::Transit, '0000888274496Q');
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('only on the sandbox', $e->getMessage());
        }

        self::assertSame([], $h->transport->requests, 'Nothing is sent to production.');
    }

    public function testASimulationTheApiRefusesIsAnExceptionWithItsFieldErrors(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-simulate-not-exists', 400)]);

        try {
            $h->client->simulateTracking('21353632', TrackingState::Transit, '0000000000000');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notExists'));
            self::assertSame(['/packageNo'], $e->getFieldPaths());
        }
    }

    // -- Points -----------------------------------------------------------------------------------------

    public function testPointsAreSearchedWithoutLoggingIn(): void
    {
        $client = new FurgonetkaApiClient(TokenFactory::config(), $transport = new FakeTransport([Fixtures::response('points/phrase')]));

        $points = $client->findPoints(new PointsQuery([PointService::Inpost, PointService::Dpd], searchPhrase: '00-026 Warszawa', limit: 3));

        self::assertCount(2, $points);
        self::assertSame('POST ' . self::HOST . '/points/map', $transport->requests[0]->method . ' ' . $transport->requests[0]->url);
        self::assertNull($transport->requests[0]->header('Authorization'), 'The points map is public.');
        self::assertSame(
            ['location' => ['search_phrase' => '00-026 Warszawa'], 'filters' => ['services' => ['inpost', 'dpd'], 'limit' => 3]],
            json_decode((string) $transport->requests[0]->body, true),
        );
    }

    public function testOnePointIsLookedUpById(): void
    {
        $h = new ClientHarness([Fixtures::response('points/by-id')]);

        $points = $h->client->findPoints(PointsQuery::forPoint('WAW099', [PointService::Inpost]));

        self::assertSame('WAW099', $points->first()?->identifier());
        self::assertSame('WAW099', $points->searchedPointId);
        self::assertSame(['search_phrase' => 'WAW099'], $h->body()['location']);
        self::assertSame('WAW099', $h->body()['filters']['point_id']);
    }

    public function testACoordinateSearchSendsTheCentre(): void
    {
        $h = new ClientHarness([Fixtures::response('points/coordinates')]);

        $h->client->findPoints(new PointsQuery([PointService::Orlen], coordinates: new Coordinates(52.2297, 21.0122), maxDistanceKm: 2.0));

        self::assertSame(['coordinates' => ['latitude' => 52.2297, 'longitude' => 21.0122], 'points_max_distance' => 2], $h->body()['location']);
    }

    public function testASearchWithoutALocationIsA404WithThePathLocation(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/not-found-points-location', 404)]);

        try {
            $h->client->findPoints(new PointsQuery([PointService::Dpd], searchPhrase: 'x'));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiNotFoundException $e) {
            self::assertSame(['/location'], $e->getFieldPaths());
        }
    }

    // -- Webhook ----------------------------------------------------------------------------------------

    public function testTheWebhookSettingsOfAnAccountWithoutOne(): void
    {
        $h = new ClientHarness([Fixtures::response('webhook/settings-empty')]);

        $settings = $h->client->fetchWebhookSettings();

        self::assertFalse($settings->isConfigured());
        self::assertSame(['GET ' . self::HOST . '/account/settings/notification/webhook'], $h->sent());
    }

    public function testTheTokenOfTheWebhookIsMaskedInTheDebugReportOfAFailure(): void
    {
        $body = '{"url":"https://shop.example/hook","format":"json","token":"' . self::TOKEN . '"}';
        $h = new ClientHarness([new TransportResponse(502, $body, ['content-type' => 'application/json'])], config: TokenFactory::config(debug: true));

        try {
            $h->client->fetchWebhookSettings();
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiServerException $e) {
            self::assertNotNull($e->getRawResponse());
            self::assertStringNotContainsString(self::TOKEN, (string) $e->getRawResponse());
            self::assertStringNotContainsString(self::TOKEN, $e->getDebugReport());
            self::assertStringContainsString('https://shop.example/hook', (string) $e->getRawResponse(), 'The rest of the body stays readable.');
        }

        self::assertStringNotContainsString(self::TOKEN, $h->logger->dump());
    }

    public function testTheFirstPageOfTheWebhookLogHasNoCursor(): void
    {
        $h = new ClientHarness([Fixtures::response('webhook/logs-empty')]);

        $page = $h->client->listWebhookLogs();

        self::assertCount(0, $page);
        self::assertSame(['GET ' . self::HOST . '/account/settings/notification/webhook/logs'], $h->sent());
    }

    public function testTheNextPageContinuesAfterTheLastEntryWithBothCursorValues(): void
    {
        $h = new ClientHarness([Fixtures::response('webhook/logs-empty')]);
        $last = new WebhookLog(id: 2, datetimeSend: new \DateTimeImmutable('2026-09-29T08:20:00+02:00'));

        $h->client->listWebhookLogs($last);

        self::assertSame(
            self::HOST . '/account/settings/notification/webhook/logs?lastId=2&lastDatetimeSend=2026-09-29T08%3A20%3A00%2B02%3A00',
            $h->transport->requests[0]->url,
        );
    }

    /** @return iterable<string, array{WebhookLog}> */
    public static function incompleteCursors(): iterable
    {
        yield 'no id' => [new WebhookLog(datetimeSend: new \DateTimeImmutable('2026-09-29T08:20:00+02:00'))];
        yield 'no time' => [new WebhookLog(id: 2)];
    }

    #[DataProvider('incompleteCursors')]
    public function testAnEntryWithoutAnIdOrATimeCannotBeACursor(WebhookLog $entry): void
    {
        $h = new ClientHarness();

        try {
            $h->client->listWebhookLogs($entry);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('together', $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }
}
