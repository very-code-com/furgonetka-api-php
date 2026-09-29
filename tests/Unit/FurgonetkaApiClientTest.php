<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Auth\Store\InMemoryTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\TokenProviderInterface;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ListType;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Query\PackageFilters;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * The client's account, validation, price and shipment operations on the real bodies: what goes out
 * (method, URL, headers, body) and what comes back. The commands are in
 * {@see FurgonetkaApiClientCommandsTest}.
 */
final class FurgonetkaApiClientTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';

    // -- Account and configuration ------------------------------------------------------------

    public function testTheServicesAreReadWithTheUserToken(): void
    {
        $h = new ClientHarness([Fixtures::response('account/services')]);

        $services = $h->client->listServices();

        self::assertCount(16, $services);
        self::assertSame(['GET ' . self::HOST . '/account/services'], $h->sent());
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Accept'));
    }

    public function testTheCompaniesAreAList(): void
    {
        $h = new ClientHarness([Fixtures::response('account/companies')]);

        $companies = $h->client->listCompanies();

        self::assertCount(1, $companies);
        self::assertSame(508899, $companies[0]->id);
    }

    public function testTheCompanyIsSentOnlyByAClientActingForOne(): void
    {
        $h = new ClientHarness([Fixtures::response('account/companies'), Fixtures::response('account/companies')]);

        $h->client->listCompanies();
        $h->client->withCompany(508899)->listCompanies();

        self::assertNull($h->transport->requests[0]->header(FurgonetkaApiConfig::HEADER_COMPANY_ID));
        self::assertSame('508899', $h->transport->requests[1]->header(FurgonetkaApiConfig::HEADER_COMPANY_ID));
    }

    public function testThePublicEndpointsSendNoToken(): void
    {
        $h = new ClientHarness([Fixtures::response('account/allowed-countries'), Fixtures::response('account/dictionary')]);

        $countries = $h->client->fetchAllowedCountries();
        $dictionary = $h->client->fetchDictionary();

        self::assertCount(79, $countries->shipment);
        self::assertSame('DPD', $dictionary->carriers()['dpd']);
        self::assertNull($h->transport->requests[0]->header('Authorization'));
        self::assertNull($h->transport->requests[1]->header('Authorization'));
        self::assertSame(['GET ' . self::HOST . '/configuration/allowed-countries', 'GET ' . self::HOST . '/configuration/dictionary'], $h->sent());
    }

    public function testAClientWithoutATokenSourceCanCallPublicEndpointsOnly(): void
    {
        $client = new FurgonetkaApiClient(TokenFactory::config(), new FakeTransport([Fixtures::response('account/dictionary')]));

        self::assertNotEmpty($client->fetchDictionary()->carriers());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('only call public endpoints');

        $client->listServices();
    }

    // -- Regulations --------------------------------------------------------------------------

    public function testTheRegulationsAreListedWithTheirFilters(): void
    {
        $h = new ClientHarness([Fixtures::response('account/regulations'), Fixtures::response('account/regulations')]);

        $all = $h->client->listRegulations();
        $h->client->listRegulations('settings', true);

        self::assertCount(4, $all);
        self::assertSame(self::HOST . '/regulations', $h->transport->requests[0]->url);
        self::assertSame(self::HOST . '/regulations?type=settings&companyOwnerRegulations=true', $h->transport->requests[1]->url);
    }

    public function testTheServicesThatNeedRegulationsAreAskedForByShipment(): void
    {
        $h = new ClientHarness([Fixtures::response('account/regulations-for-packages')]);

        $services = $h->client->regulationsForPackages(['21353373', 21353374]);

        self::assertSame(['dpd'], $services);
        self::assertSame(self::HOST . '/packages/regulations?packagesIds%5B0%5D=21353373&packagesIds%5B1%5D=21353374', $h->transport->requests[0]->url);
    }

    public function testPendingRegulationsAreThoseOfTheNamedServicesThatAreNotAccepted(): void
    {
        // The fixture says dpd, and dpd is accepted on the sandbox account: nothing is pending.
        $h = new ClientHarness([Fixtures::response('account/regulations-for-packages'), Fixtures::response('account/regulations')]);

        self::assertSame([], $h->client->pendingRegulations(['21353373']));
        self::assertCount(2, $h->transport->requests);
    }

    public function testAPendingRegulationIsFoundAndNothingElseIsOffered(): void
    {
        $h = new ClientHarness([
            FakeTransport::jsonResponse('{"servicesRegulation":["fedex"]}'),
            Fixtures::response('account/regulations'),
        ]);

        $pending = $h->client->pendingRegulations(['21353373']);

        self::assertCount(1, $pending);
        self::assertSame('fedex', $pending[0]->service);
        self::assertFalse($pending[0]->accepted);
    }

    public function testNothingPendingCostsNoSecondRequest(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"servicesRegulation":[]}')]);

        self::assertSame([], $h->client->pendingRegulations(['21353373']));
        self::assertCount(1, $h->transport->requests, 'The whole regulations list is not fetched when no service is named.');
    }

    public function testAcceptingSendsTheVersionAndDatetimeAsTheyCame(): void
    {
        $h = new ClientHarness([Fixtures::response('account/regulations'), Fixtures::response('account/regulations')]);
        $fedex = $h->client->listRegulations()[0];

        $h->client->acceptRegulations([$fedex]);

        self::assertSame('POST ' . self::HOST . '/regulations', $h->sent()[1]);
        self::assertSame(
            ['regulations' => [['service' => 'fedex', 'name' => 'FedEx Krajowy', 'version' => 1785103203, 'datetime' => '2026-07-27T00:00:03+02:00', 'accepted' => true]]],
            $h->body(1),
        );
        self::assertTrue($h->logger->has(LogLevel::INFO, 'accepting regulations'), 'A legal act of the account leaves a trace in the log.');
    }

    public function testAcceptingNothingIsRefusedBeforeAnyRequest(): void
    {
        $h = new ClientHarness();

        try {
            $h->client->acceptRegulations([]);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least one regulation', $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    // -- Validation -----------------------------------------------------------------------------

    public function testAValidShipmentIsA204(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        self::assertSame([], $h->client->validateRemotely(ShipmentFactory::request()));
        self::assertSame('POST ' . self::HOST . '/packages/validate', $h->sent()[0]);
        self::assertSame('application/vnd.furgonetka.v2+json', $h->transport->requests[0]->header('Content-Type'));
    }

    public function testAnInvalidShipmentIsTheListOfItsFieldErrors(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-validation', 400)]);

        $errors = $h->client->validateRemotely(ShipmentFactory::request());

        self::assertCount(3, $errors);
        self::assertSame('/receiver/postcode', $errors[0]->path);
        self::assertSame('format', $errors[0]->code);
    }

    public function testAnEmptyBodyThatAnswers200IsNotValid(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/validate-empty-body-200')]);

        $this->expectException(FurgonetkaApiResponseParseException::class);
        $this->expectExceptionMessage('only 204 means the shipment is valid');

        $h->client->validateRemotely(ShipmentFactory::request());
    }

    public function testAnErrorThatIsNotAValidationAnswerStillThrows(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401)]);

        $this->expectException(FurgonetkaApiAuthException::class);

        $h->client->validateRemotely(ShipmentFactory::request());
    }

    public function testLocalValidationNeedsNoRequest(): void
    {
        $h = new ClientHarness();
        $request = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'GB'));

        $errors = $h->client->validateLocally($request);

        self::assertSame(['/duty: is required for shipments to GB.'], $errors);
        self::assertSame([], $h->transport->requests);
    }

    public function testTheCarrierRulesNeedTheCarrierFromTheArgumentOrTheServiceList(): void
    {
        $twoParcels = ShipmentFactory::request(serviceId: 12014778, parcels: [ShipmentFactory::parcel(), ShipmentFactory::parcel()]);
        $h = new ClientHarness([Fixtures::response('account/services')]);

        self::assertSame([], $h->client->validateLocally($twoParcels), 'The service id alone tells nothing about the carrier.');
        self::assertSame([], $h->client->validateLocally($twoParcels, Carrier::Ups), 'With the carrier but not the owner, UPS\'s parcel limit is only a warning.');
        self::assertCount(1, $h->client->warningsFor($twoParcels, Carrier::Ups));

        $h->client->listServices();

        self::assertSame(
            ['/parcels: at most 1 parcels per shipment for ups, got 2.'],
            $h->client->validateLocally($twoParcels),
            'After listServices() the carrier and the owner are known: on the Furgonetka contract UPS takes one parcel.',
        );
    }

    public function testDutyCountriesFromTheAllowedListAreRememberedAfterTheyWereFetched(): void
    {
        $toChina = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'CN'));
        $h = new ClientHarness([Fixtures::response('account/allowed-countries')]);

        self::assertSame([], $h->client->validateLocally($toChina), 'China is not among the countries the library knows to need duty.');

        $h->client->fetchAllowedCountries();

        self::assertSame(['/duty: is required for shipments to CN.'], $h->client->validateLocally($toChina));
    }

    public function testNoRequestIsMadeToLearnTheServicesOrCountries(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/created')]);

        $h->client->createPackage(ShipmentFactory::request());

        self::assertCount(1, $h->transport->requests, 'Validation must not fetch anything behind the caller\'s back.');
    }

    // -- Prices ---------------------------------------------------------------------------------------

    public function testPricesAreAskedForWithTheScopeAndTheShipment(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/calculate-price')]);

        $prices = $h->client->calculatePrices(ShipmentFactory::request(), PriceScope::carriers(Carrier::Dpd, 'gls'));

        self::assertCount(8, $prices);
        self::assertSame('POST ' . self::HOST . '/packages/calculate-price', $h->sent()[0]);
        self::assertSame('application/vnd.furgonetka.v2+json', $h->transport->requests[0]->header('Content-Type'));
        self::assertSame(['service' => ['dpd', 'gls']], $h->body()['services']);
        self::assertEquals(ShipmentFactory::request()->toArray(), $h->body()['package'], 'Equal as JSON: 5.0 is written as 5.');
    }

    public function testPricesByServiceId(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/calculate-price')]);

        $h->client->calculatePrices(ShipmentFactory::request(), PriceScope::serviceIds(12014776));

        self::assertSame(['service_id' => [12014776]], $h->body()['services']);
    }

    public function testPricingRunsNoLocalValidationSoAnIncompleteShipmentCanBePriced(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/calculate-price')]);
        $toGb = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'GB'));

        $h->client->calculatePrices($toGb, PriceScope::carriers('dpd'));

        self::assertCount(1, $h->transport->requests);
    }

    // -- Shipments --------------------------------------------------------------------------------------

    public function testAShipmentIsCreated(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/created', 201)]);

        $package = $h->client->createPackage(ShipmentFactory::request());

        self::assertSame('21353373', $package->id);
        self::assertSame('POST ' . self::HOST . '/packages', $h->sent()[0]);
        self::assertEquals(ShipmentFactory::request()->toArray(), $h->body(), 'Equal as JSON: 5.0 is written as 5.');
        self::assertSame('application/vnd.furgonetka.v2+json', $h->transport->requests[0]->header('Content-Type'));
        self::assertTrue($h->logger->has(LogLevel::INFO, 'creating a shipment'));
    }

    public function testABrokenLocalRuleStopsTheRequestBeforeItIsSent(): void
    {
        $h = new ClientHarness();
        $toGb = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'GB'));

        try {
            $h->client->createPackage($toGb);
            self::fail('Expected a validation exception.');
        } catch (FurgonetkaApiValidationException $e) {
            self::assertSame(['/duty: is required for shipments to GB.'], $e->errors);
        }

        self::assertSame([], $h->transport->requests);
        self::assertTrue($h->logger->has(LogLevel::ERROR, '/duty'));
    }

    public function testWarningsAreLoggedButNeverStopTheRequest(): void
    {
        $h = new ClientHarness([Fixtures::response('account/services'), Fixtures::response('packages/created')]);
        $request = ShipmentFactory::request(reference: str_repeat('R', 30));
        $h->client->listServices();

        self::assertNotSame([], $h->client->warningsFor($request));

        $h->client->createPackage($request);

        self::assertCount(2, $h->transport->requests, 'The shipment was sent in spite of the warning.');
        self::assertTrue($h->logger->has(LogLevel::WARNING, 'reference'));
        self::assertFalse($h->logger->has(LogLevel::ERROR, 'reference'));
    }

    public function testAShipmentIsUpdatedByReplacingItWhole(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/created')]);

        $package = $h->client->updatePackage(21353373, ShipmentFactory::request(reference: 'Test 1'));

        self::assertSame('21353373', $package->id);
        self::assertSame('PUT ' . self::HOST . '/packages/21353373', $h->sent()[0]);
        self::assertSame('Test 1', $h->body()['user_reference_number']);
    }

    public function testUpdatingAlsoRunsTheLocalRules(): void
    {
        $h = new ClientHarness();

        $this->expectException(FurgonetkaApiValidationException::class);

        try {
            $h->client->updatePackage('21353373', ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'GB')));
        } finally {
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testAShipmentIsReadByIdOrByAPackageObject(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/ordered'), Fixtures::response('packages/ordered'), Fixtures::response('packages/ordered')]);

        $one = $h->client->fetchPackage('21353373');
        $h->client->fetchPackage($one);
        $h->client->fetchPackage('0000888261798Q', true, 'sent');

        self::assertSame(self::HOST . '/packages/21353373', $h->transport->requests[0]->url);
        self::assertSame(self::HOST . '/packages/' . $one->id, $h->transport->requests[1]->url);
        self::assertSame(self::HOST . '/packages/0000888261798Q?original_services=true&type=sent', $h->transport->requests[2]->url, 'A waybill number is accepted here.');
    }

    public function testAShipmentThatIsNotYoursIsA400NotA404(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-not-owner', 400)]);

        try {
            $h->client->fetchPackage('1');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertNotSame([], $e->errors);
        }
    }

    public function testAMissingShipmentIsA404(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/not-found-package', 404)]);

        $this->expectException(FurgonetkaApiNotFoundException::class);

        $h->client->fetchPackage('999999999');
    }

    public function testTheListIsFilteredAndPagedInTheQueryString(): void
    {
        $h = new ClientHarness([Fixtures::response('packages/list-page'), Fixtures::response('packages/list-waiting')]);

        $page = $h->client->listPackages(new PackageListQuery(
            limit: 5,
            lastPackageId: 10,
            listType: ListType::Waiting,
            query: 'a b',
            filters: new PackageFilters(services: ['dpd'], withCod: true),
        ));
        $h->client->listPackages();

        self::assertCount(2, $page);
        self::assertSame(
            self::HOST . '/packages?limit=5&last_package_id=10&list_type=waiting&query=a%20b&filters%5Bservices%5D%5B0%5D=dpd&filters%5Bwith_cod%5D=true',
            $h->transport->requests[0]->url,
        );
        self::assertSame(self::HOST . '/packages', $h->transport->requests[1]->url);
    }

    public function testAShipmentIsDeletedWithA204(): void
    {
        $h = new ClientHarness([Fixtures::empty(204), Fixtures::empty(204)]);

        $h->client->deletePackage(21353373);
        $h->client->deletePackages(['1', 2, new Package(id: '3')]);

        self::assertSame('DELETE ' . self::HOST . '/packages/21353373', $h->sent()[0]);
        self::assertSame('DELETE ' . self::HOST . '/packages', $h->sent()[1]);
        self::assertSame(['packages' => [['id' => '1'], ['id' => '2'], ['id' => '3']]], $h->body(1));
    }

    // -- Shipment ids are checked before anything is sent ---------------------------------------------------

    /** @return iterable<string, array{callable(FurgonetkaApiClient): mixed, string}> */
    public static function badIds(): iterable
    {
        yield 'empty id' => [static fn (FurgonetkaApiClient $c) => $c->fetchPackage(' '), 'must not be empty'];
        yield 'a package without an id' => [static fn (FurgonetkaApiClient $c) => $c->deletePackage(new Package()), 'must not be empty'];
        yield 'no shipments to delete' => [static fn (FurgonetkaApiClient $c) => $c->deletePackages([]), 'At least one shipment'];
        yield 'a waybill number in a bulk call' => [static fn (FurgonetkaApiClient $c) => $c->deletePackages(['0000888261798Q']), 'not a waybill number'];
        yield 'a waybill number where an id is required' => [static fn (FurgonetkaApiClient $c) => $c->regulationsForPackages(['1x']), 'not a waybill number'];
        yield 'an id with a newline inside' => [static fn (FurgonetkaApiClient $c) => $c->deletePackages(["1\n2"]), 'not a waybill number'];
    }

    public function testSpacesAroundAnIdAreTrimmed(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->deletePackages([" 12\n"]);

        self::assertSame(['packages' => [['id' => '12']]], $h->body());
    }

    /** @param callable(FurgonetkaApiClient): mixed $call */
    #[DataProvider('badIds')]
    public function testABadShipmentIdIsRefusedLocally(callable $call, string $message): void
    {
        $h = new ClientHarness();

        try {
            $call($h->client);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $h->transport->requests);
    }

    // -- The 401 retry --------------------------------------------------------------------------------------------

    public function testARefusedTokenIsReplacedAndTheRequestRepeatedOnce(): void
    {
        $provider = new class implements TokenProviderInterface {
            public int $invalidated = 0;
            /** @var list<string> */
            private array $tokens = ['old-token', 'new-token'];

            public function accessToken(): string
            {
                return $this->tokens[min($this->invalidated, 1)];
            }

            public function invalidate(): void
            {
                ++$this->invalidated;
            }
        };
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401), Fixtures::response('account/companies')], $provider);

        $companies = $h->client->listCompanies();

        self::assertCount(1, $companies);
        self::assertSame(1, $provider->invalidated);
        self::assertSame(['Bearer old-token', 'Bearer new-token'], array_map(static fn ($r): ?string => $r->header('Authorization'), $h->transport->requests));
        self::assertTrue($h->logger->has(LogLevel::INFO, 'access token was refused'));
    }

    public function testASecond401IsNotRetriedAgain(): void
    {
        $provider = new class implements TokenProviderInterface {
            public int $invalidated = 0;

            public function accessToken(): string
            {
                return 't' . $this->invalidated;
            }

            public function invalidate(): void
            {
                ++$this->invalidated;
            }
        };
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401), Fixtures::response('errors/unauthorized-bad-token', 401)], $provider);

        $this->expectException(FurgonetkaApiAuthException::class);

        try {
            $h->client->listCompanies();
        } finally {
            self::assertSame(1, $provider->invalidated);
            self::assertCount(2, $h->transport->requests);
        }
    }

    public function testAStaticTokenIsNotRetriedBecauseThereIsNoOtherToOffer(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401)]);

        $this->expectException(FurgonetkaApiAuthException::class);

        try {
            $h->client->listCompanies();
        } finally {
            self::assertCount(1, $h->transport->requests);
        }
    }

    public function testAPublicEndpointDoesNotRetryOn401(): void
    {
        $provider = new class implements TokenProviderInterface {
            public int $invalidated = 0;

            public function accessToken(): string
            {
                return 'never-used';
            }

            public function invalidate(): void
            {
                ++$this->invalidated;
            }
        };
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401)], $provider);

        try {
            $h->client->fetchDictionary();
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiAuthException) {
            self::assertSame(0, $provider->invalidated);
        }
    }

    // -- Errors and answers that are not JSON -------------------------------------------------------------------------

    public function testAServerErrorIsAnException(): void
    {
        $h = new ClientHarness([new TransportResponse(503, 'Service Unavailable', ['content-type' => 'text/html'])]);

        $this->expectException(FurgonetkaApiServerException::class);

        $h->client->listServices();
    }

    public function testAnAnswerThatIsNotAnObjectIsAParseError(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('[1,2]'), FakeTransport::jsonResponse('<html lang="en">')]);

        try {
            $h->client->listServices();
            self::fail('Expected a parse error.');
        } catch (FurgonetkaApiResponseParseException) {
        }

        $this->expectException(FurgonetkaApiResponseParseException::class);

        $h->client->listCompanies();
    }

    public function testATransportFailureIsLoggedAndRethrown(): void
    {
        $h = new ClientHarness([new FurgonetkaApiTransportException('Could not resolve host')]);

        try {
            $h->client->listServices();
            self::fail('Expected a transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame('Could not resolve host', $e->getMessage());
        }

        self::assertTrue($h->logger->has(LogLevel::ERROR, 'Could not resolve host'));
    }

    public function testDebugModeAttachesTheRedactedAnswerToTheException(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-not-owner', 400)], config: TokenFactory::config(debug: true));

        try {
            $h->client->fetchPackage('1');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertNotNull($e->getRawResponse());
        }
    }

    public function testWithoutDebugModeNoRawAnswerIsKept(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-not-owner', 400)]);

        try {
            $h->client->fetchPackage('1');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertNull($e->getRawResponse());
        }
    }

    // -- Rate limit ----------------------------------------------------------------------------------------------------

    public function testTheRateLimitIsKeptFromTheLastAnswer(): void
    {
        $h = new ClientHarness([Fixtures::response('account/companies')]);

        $before = $h->client->lastRateLimit();

        $h->client->listCompanies();

        $limit = $h->client->lastRateLimit();
        self::assertNull($before);
        self::assertNotNull($limit);
        self::assertSame(500, $limit->limit);
        self::assertSame(499, $limit->remaining);
        self::assertFalse($h->logger->has(LogLevel::WARNING, 'request limit'));
    }

    public function testALowRateLimitIsLoggedAsAWarning(): void
    {
        $answer = new TransportResponse(200, Fixtures::raw('account/companies'), ['content-type' => 'application/json', 'x-ratelimit-limit' => '500', 'x-ratelimit-remaining' => '20', 'x-ratelimit-retry-after' => '0']);
        $h = new ClientHarness([$answer]);

        $h->client->listCompanies();

        self::assertSame(20, $h->client->lastRateLimit()?->remaining);
        self::assertTrue($h->logger->has(LogLevel::WARNING, 'request limit'));
    }

    // -- OAuth entry points ---------------------------------------------------------------------------------------------------

    public function testConnectingNeedsAClientBuiltWithATokenStore(): void
    {
        $h = new ClientHarness();

        $this->expectException(\LogicException::class);

        $h->client->connectWithPassword('user@example.com', 'a-password-of-15c');
    }

    public function testTheOAuthClientNeedsTheApplicationKeys(): void
    {
        $h = new ClientHarness();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OAuth application keys');

        $h->client->oauth();
    }

    public function testAStoreWithoutApplicationKeysIsRefusedAtCreation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth application keys');

        FurgonetkaApiClient::create(
            FurgonetkaApiConfig::sandbox(),
            new InMemoryTokenStore(),
            transport: new FakeTransport(),
        );
    }
}
