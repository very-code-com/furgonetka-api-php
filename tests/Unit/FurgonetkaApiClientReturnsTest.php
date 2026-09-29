<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingReceiver;
use VeryCodeCom\FurgonetkaApi\Dto\SendToMeRequest;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Enum\ReaddressingStatus;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Returns, readdressing and the send-to-me link through the client. What goes out and how the
 * errors come back are real (captured on the sandbox); a successful return or readdressing is not,
 * because the sandbox refused every shipment, so those answers are built from the specification.
 */
final class FurgonetkaApiClientReturnsTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';
    private const UUID = '8b881c52-6473-467a-a7c9-d0aa4c4f3bec';

    private static function receiver(): ReaddressingReceiver
    {
        return new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '500200300', name: 'Piotr Zielinski', email: 'piotr@example.com');
    }

    /** A successful answer of the two PUT operations, from the specification. */
    private static function paymentResponse(): TransportResponse
    {
        return FakeTransport::jsonResponse('{"uuid":"' . self::UUID . '","paymentUrl":"https://pay.example/1","summaryUrl":"https://shop.example/summary/1"}');
    }

    /** The uuid the client put at the end of the n-th request's URL. */
    private static function uuidOf(ClientHarness $h, int $index = 0): string
    {
        $url = $h->transport->requests[$index]->url;
        preg_match('#/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$#D', $url, $match);

        return $match[1] ?? throw new \LogicException("No version 4 UUID at the end of {$url}.");
    }

    // -- The price of a return ---------------------------------------------------------------------

    public function testTheReturnPriceIsAskedForWithTheIdAsANumber(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"price":20.76,"scenario":null}')]);

        $estimate = $h->client->calculateReturnPrice('21353373');

        self::assertSame(20.76, $estimate->price);
        self::assertSame('POST ' . self::HOST . '/packages/return/calculate-price', $h->sent()[0]);
        self::assertSame(['packageId' => 21353373], $h->body());
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Content-Type'));
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
    }

    public function testAShipmentObjectOrANumberWorksAsTheId(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"price":1.5}'), FakeTransport::jsonResponse('{"price":1.5}')]);

        $h->client->calculateReturnPrice(21353373);
        $h->client->calculateReturnPrice(new Package(id: '21353374'));

        self::assertSame(21353373, $h->body(0)['packageId']);
        self::assertSame(21353374, $h->body(1)['packageId']);
    }

    /** @return iterable<string, array{string|int|Package}> */
    public static function idsRefused(): iterable
    {
        yield 'a waybill number' => ['0000888274496Q'];
        yield 'an empty id' => [' '];
        yield 'an id with a newline inside' => ["1\n2"];
        yield 'a package without an id' => [new Package()];
    }

    #[DataProvider('idsRefused')]
    public function testAnIdThatIsNotTheNumberFurgonetkaGaveIsRefusedBeforeARequest(string|int|Package $id): void
    {
        $h = new ClientHarness();

        foreach ([
            static fn (FurgonetkaApiClient $c) => $c->calculateReturnPrice($id),
            static fn (FurgonetkaApiClient $c) => $c->returnPackage($id),
            static fn (FurgonetkaApiClient $c) => $c->calculateReaddressingPrice($id, self::receiver()),
            static fn (FurgonetkaApiClient $c) => $c->readdressPackage($id, self::receiver()),
        ] as $call) {
            try {
                $call($h->client);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $h->transport->requests);
    }

    public function testAShipmentThatCannotBeReturnedIsA400WithTheCodeNotPossible(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-return-not-possible', 400)]);

        try {
            $h->client->calculateReturnPrice('21351614');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notPossible'));
            self::assertSame(['/packageId'], $e->getFieldPaths());
            self::assertStringContainsString('cannot be returned', $e->getMessage());
        }
    }

    public function testAShipmentThatDoesNotExistIsA400WithTheCodePackageDoNotExist(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-package-does-not-exist', 400)]);

        try {
            $h->client->calculateReturnPrice('999999999');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('packageDoNotExist'));
        }
    }

    public function testAnUnhandledErrorOnTheFurgonetkaSideIsAServerException(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/server-error-unhandled', 500)]);

        try {
            $h->client->calculateReturnPrice('21351613');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiServerException $e) {
            self::assertSame(500, $e->statusCode);
            self::assertStringContainsString('Unhandled error', $e->getMessage());
        }
    }

    // -- Returning a shipment ----------------------------------------------------------------------

    public function testAReturnIsRequestedUnderAFreshUuidAndTheLinksComeBackAtOnce(): void
    {
        $h = new ClientHarness([self::paymentResponse()]);

        $payment = $h->client->returnPackage('21353373');

        self::assertSame('https://pay.example/1', $payment->paymentUrl);
        self::assertSame('https://shop.example/summary/1', $payment->summaryUrl);
        self::assertCount(1, $h->transport->requests, 'It does not wait for the payment or poll anything.');
        self::assertSame('PUT ' . self::HOST . '/packages/return/' . self::uuidOf($h), $h->sent()[0]);
        self::assertSame(['packageId' => '21353373'], $h->body(), 'The id is text here, as in the specification.');
        self::assertTrue($h->logger->has(LogLevel::INFO, 'the return requested'));
    }

    public function testTheRegulationTimestampIsSentAsTextAndOnlyWhenGiven(): void
    {
        $h = new ClientHarness([self::paymentResponse(), self::paymentResponse(), self::paymentResponse()]);

        $h->client->returnPackage(1, 1600317819);
        $h->client->returnPackage(1, ' 1600317819 ');
        $h->client->returnPackage(1, '  ');

        self::assertSame(['packageId' => '1', 'regulationsAccept' => '1600317819'], $h->body(0));
        self::assertSame('1600317819', $h->body(1)['regulationsAccept']);
        self::assertSame(['packageId' => '1'], $h->body(2), 'A blank value is no acceptance.');
    }

    public function testAUuidOfYourOwnIsUsedAsItIs(): void
    {
        $h = new ClientHarness([self::paymentResponse()]);

        $h->client->returnPackage('1', uuid: strtoupper(self::UUID));

        self::assertSame('PUT ' . self::HOST . '/packages/return/' . self::UUID, $h->sent()[0], 'Lower-cased, as the client does everywhere.');
    }

    public function testAMalformedUuidIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UUID');

        try {
            $h->client->returnPackage('1', uuid: 'not-a-uuid');
        } finally {
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testARepeatedRequestIsNotTakenAsDoneBecauseItsLinksCannotBeReadAgain(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400)]);

        try {
            $h->client->returnPackage('1', uuid: self::UUID);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandExists'));
        }
    }

    public function testANetworkFailureCarriesTheUuidSoTheRequestCanBeIdentified(): void
    {
        $h = new ClientHarness([new FurgonetkaApiTransportException('Operation timed out')]);

        try {
            $h->client->returnPackage('1');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid());
        }
    }

    public function testTheErrorsOfTheReturnShipmentThatFurgonetkaBuildsComeBackWithShipmentPaths(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-return-shipment-invalid', 400)]);

        try {
            $h->client->returnPackage('21351614');
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/service_id', '/pickup/postcode', '/receiver/phone'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('noService'));
        }
    }

    // -- Readdressing ---------------------------------------------------------------------------------

    public function testTheReaddressingPriceSendsTheReceiverAndTheIdAsText(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"price":12.5,"scenario":null}')]);

        $estimate = $h->client->calculateReaddressingPrice(21353373, self::receiver(), 1600317819);

        self::assertSame(12.5, $estimate->price);
        self::assertSame('POST ' . self::HOST . '/packages/readdressing/calculate-price', $h->sent()[0]);
        self::assertSame(
            [
                'packageId' => '21353373',
                'receiver' => ['name' => 'Piotr Zielinski', 'postcode' => '00-838', 'city' => 'Warszawa', 'street' => 'Prosta 51', 'email' => 'piotr@example.com', 'phone' => '500200300'],
                'regulationsAccept' => '1600317819',
            ],
            $h->body(),
        );
    }

    public function testAShipmentThatCannotBeReaddressedComesBackWithTheFieldErrorsOfTheReceiverNextToIt(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-readdress-receiver-empty', 400)]);

        try {
            $h->client->calculateReaddressingPrice('21351614', self::receiver());
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertCount(7, $e->errors);
            self::assertSame('notPossible', $e->errors[0]->code);
            self::assertSame(['/packageId', '/receiver/name', '/receiver/company', '/receiver/postcode', '/receiver/city', '/receiver/street', '/receiver/phone'], $e->getFieldPaths());
        }
    }

    public function testAReaddressingIsRequestedWithTheCarrierAndTheLinksComeBack(): void
    {
        $h = new ClientHarness([self::paymentResponse()]);

        $payment = $h->client->readdressPackage('21353373', self::receiver(), Carrier::Dpd);

        self::assertSame(self::UUID, $payment->uuid);
        self::assertSame('PUT ' . self::HOST . '/packages/readdressing/' . self::uuidOf($h), $h->sent()[0]);
        self::assertSame('dpd', $h->body()['service']);
        self::assertSame('21353373', $h->body()['packageId']);
        self::assertSame('Piotr Zielinski', $h->body()['receiver']['name']);
        self::assertArrayNotHasKey('regulationsAccept', $h->body());
        self::assertTrue($h->logger->has(LogLevel::INFO, 'the readdressing requested'));
    }

    public function testTheCarrierIsOptionalAndCanBeText(): void
    {
        $h = new ClientHarness([self::paymentResponse(), self::paymentResponse(), self::paymentResponse()]);

        $h->client->readdressPackage('1', self::receiver());
        $h->client->readdressPackage('1', self::receiver(), ' gls ');
        $h->client->readdressPackage('1', self::receiver(), ' ');

        self::assertArrayNotHasKey('service', $h->body(0));
        self::assertSame('gls', $h->body(1)['service']);
        self::assertArrayNotHasKey('service', $h->body(2));
    }

    public function testAReaddressingThatIsNotPossibleIsA400(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-readdress-not-possible', 400)]);

        try {
            $h->client->readdressPackage('21351614', self::receiver(), Carrier::Dpd, uuid: self::UUID);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notPossible'));
            self::assertStringContainsString('cannot be readdressed', $e->getMessage());
        }
    }

    public function testTheStateOfAReaddressingIsRead(): void
    {
        // The body is built from the specification (ReaddressingResponse).
        $body = '{"status":"successful","datetime_change":"2026-09-29T10:00:00+02:00","errors":[],"uuid":"' . self::UUID . '","details":{"newReceiver":{"name":"Piotr Zielinski"},"price":12.5,"status":"new","info":"","packages_data":{"packages_groups":[{"packages":[{"id":21353373}]}]}}}';
        $h = new ClientHarness([FakeTransport::jsonResponse($body)]);

        $result = $h->client->fetchReaddressing(self::UUID);

        self::assertSame(ReaddressingStatus::New, $result->details?->status);
        self::assertSame(['21353373'], $result->details->packageIds());
        self::assertSame('GET ' . self::HOST . '/packages/readdressing/' . self::UUID, $h->sent()[0]);
    }

    public function testAnUnknownReaddressingIsA400WithTheCodeCommandNotExists(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-not-exists', 400), Fixtures::response('errors/bad-request-command-not-exists', 400)]);

        foreach ([
            static fn (FurgonetkaApiClient $c) => $c->fetchReaddressing(self::UUID),
            static fn (FurgonetkaApiClient $c) => $c->cancelReaddressing(self::UUID),
        ] as $call) {
            try {
                $call($h->client);
                self::fail('Expected an exception.');
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('commandNotExists'));
            }
        }
    }

    public function testAReaddressingIsCancelledWithADelete(): void
    {
        $h = new ClientHarness([Fixtures::empty(204)]);

        $h->client->cancelReaddressing(strtoupper(self::UUID));

        self::assertSame(['DELETE ' . self::HOST . '/packages/readdressing/' . self::UUID], $h->sent());
    }

    public function testAMalformedReaddressingUuidIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        foreach ([
            static fn (FurgonetkaApiClient $c) => $c->fetchReaddressing('x'),
            static fn (FurgonetkaApiClient $c) => $c->cancelReaddressing('../packages'),
        ] as $call) {
            try {
                $call($h->client);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('UUID', $e->getMessage());
            }
        }

        self::assertSame([], $h->transport->requests);
    }

    // -- The send to me link ------------------------------------------------------------------------------

    public function testTheSendToMeLinkNeedsNoLogin(): void
    {
        $transport = new FakeTransport([Fixtures::response('send-to-me/address')]);
        $client = new FurgonetkaApiClient(TokenFactory::config(), $transport);
        $request = new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan Kowalski', street: 'Jasna 10/12', postcode: '00-001', city: 'Warszawa');

        $link = $client->createSendToMeLink($request);

        self::assertStringStartsWith('https://sandbox.furgonetka.pl/konto/dodawanie-paczki?sendToMeData=', (string) $link->url);
        self::assertSame('POST', $transport->requests[0]->method);
        self::assertSame(self::HOST . '/packages/send-to-me-link', $transport->requests[0]->url);
        self::assertNull($transport->requests[0]->header('Authorization'), 'The operation is public.');
        self::assertSame($request->toArray(), json_decode((string) $transport->requests[0]->body, true));
    }

    public function testAPointGoesWithItsCarrier(): void
    {
        $h = new ClientHarness([Fixtures::response('send-to-me/point')]);

        $h->client->createSendToMeLink(new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan Kowalski', point: 'WAW099', service: PointService::Inpost));

        self::assertNull($h->transport->requests[0]->header('Authorization'), 'Public even when the client has a token.');
        self::assertSame('WAW099', $h->body()['receiver']['point']);
        self::assertSame('inpost', $h->body()['service']);
    }

    public function testACompanyGetsALink(): void
    {
        $h = new ClientHarness([Fixtures::response('send-to-me/company')]);

        $link = $h->client->createSendToMeLink(new SendToMeRequest('firma@example.com', '500100200', 'PL', company: 'Firma Sp. z o.o.', street: 'Jasna 10/12', postcode: '00-001', city: 'Warszawa'));

        self::assertNotNull($link->url);
    }

    public function testDataFurgonetkaRefusesComesBackAsFieldErrors(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-send-to-me-empty', 400)]);

        try {
            $h->client->createSendToMeLink(new SendToMeRequest('not-an-email', '1', 'PL', name: 'A', street: 's', postcode: 'p', city: 'c'));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame('invalid', $e->errors[0]->code);
            self::assertSame(['/receiver/email', '/receiver/phone', '/receiver/street', '/receiver/postcode', '/receiver/city', '/receiver/name', '/receiver/company'], $e->getFieldPaths());
        }
    }

    public function testACarrierThatIsNotOneOfTheApisIsA400(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-send-to-me-bad-service', 400)]);

        try {
            $h->client->createSendToMeLink(new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan', point: 'WAW099', service: 'nonsense'));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('invalidPointName'));
            self::assertTrue($e->hasErrorCode('notInArray'));
            self::assertSame(['/receiver/point', '/service'], $e->getFieldPaths());
        }
    }
}
