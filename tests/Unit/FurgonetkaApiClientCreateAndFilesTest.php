<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Auth\TokenProviderInterface;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ShipmentFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Creating a shipment as a command and uploading files, through the client, on the bodies the
 * sandbox gave: the states of both commands, the errors, the retry that must not create twice, and
 * the multipart body that leaves the machine.
 */
final class FurgonetkaApiClientCreateAndFilesTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';
    /** The UUID of the upload in the captured answers of GET /file-command. */
    private const UPLOAD = 'fdc51fd4-20c8-4418-91f8-3444ed91952e';
    private const CREATE = '39512680-0b59-4297-87c2-1209a8986323';
    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private static function uuidOf(ClientHarness $h, int $index = 0): string
    {
        $url = $h->transport->requests[$index]->url;
        preg_match('#([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})(?:\?.*)?$#D', $url, $match);

        return $match[1] ?? throw new \LogicException("No version 4 UUID in {$url}.");
    }

    // -- Creating a shipment as a command --------------------------------------------------------

    public function testAShipmentIsCreatedThroughACommandAndReadUntilItIsThere(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('create/queueing'), Fixtures::response('create/successful')]);
        $request = ShipmentFactory::request(reference: 'Async');

        $result = $h->client->createPackageAsync($request);

        self::assertTrue($result->isSuccessful());
        self::assertSame('21354137', $result->packageId);
        $uuid = self::uuidOf($h);
        self::assertSame(
            ['PUT ' . self::HOST . "/create-package-command/{$uuid}", 'GET ' . self::HOST . "/create-package-command/{$uuid}", 'GET ' . self::HOST . "/create-package-command/{$uuid}"],
            $h->sent(),
        );
        self::assertEquals($request->toArray(), $h->body(), 'The same body as createPackage(), equal as JSON.');
        self::assertSame('application/vnd.furgonetka.v2+json', $h->transport->requests[0]->header('Content-Type'), 'The newest version of the body.');
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[1]->header('Accept'));
        self::assertSame([1.0], $h->sleeper->sleeps);
    }

    public function testAFailedCreationIsAResultWithTheErrorsOfTheShipment(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('create/error-postcode')]);

        $result = $h->client->createPackageAsync(ShipmentFactory::request());

        self::assertTrue($result->hasFailed());
        self::assertNull($result->packageId);
        self::assertSame(['unavailable', 'notExists'], array_map(static fn ($e): ?string => $e->code, $result->errors));
    }

    public function testALocalRuleFurgonetkaEnforcesStopsTheCommandBeforeItIsSent(): void
    {
        $h = new ClientHarness();
        $toGb = ShipmentFactory::request(receiver: ShipmentFactory::receiver(country: 'GB'));

        try {
            $h->client->createPackageAsync($toGb);
            self::fail('Expected a validation exception.');
        } catch (FurgonetkaApiValidationException $e) {
            self::assertSame(['/duty: is required for shipments to GB.'], $e->errors);
        }

        self::assertSame([], $h->transport->requests);
        self::assertTrue($h->logger->has(LogLevel::ERROR, '/duty'));
    }

    public function testARetryWithTheSameUuidReadsTheCommandInsteadOfCreatingASecondShipment(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400), Fixtures::response('create/successful')]);

        $result = $h->client->createPackageAsync(ShipmentFactory::request(), self::CREATE);

        self::assertSame('21354137', $result->packageId);
        self::assertSame('PUT ' . self::HOST . '/create-package-command/' . self::CREATE, $h->sent()[0]);
        self::assertSame('GET ' . self::HOST . '/create-package-command/' . self::CREATE, $h->sent()[1]);
    }

    public function testACommandExistsAnswerToAGeneratedCreateUuidIsAnError(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400)]);

        $this->expectException(FurgonetkaApiBadRequestException::class);

        $h->client->createPackageAsync(ShipmentFactory::request());
    }

    public function testACreationThatNeverFinishesEndsInATimeoutThatCarriesTheUuid(): void
    {
        $queueing = Fixtures::response('create/queueing');
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), $queueing, $queueing, $queueing, $queueing], polling: new PollingPolicy(timeout: 3.0));

        try {
            $h->client->createPackageAsync(ShipmentFactory::request());
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid());
            self::assertSame('queueing', $e->lastStatus());
        }
    }

    public function testTheTwoHalvesCanBeCalledApart(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('create/successful')]);

        $uuid = $h->client->submitCreatePackage(ShipmentFactory::request(), self::CREATE);
        $result = $h->client->fetchCreatePackageCommand($uuid);

        self::assertSame(self::CREATE, $uuid);
        self::assertTrue($result->isSuccessful());
        self::assertSame([], $h->sleeper->sleeps);
    }

    public function testAnUnknownCreationIsA400WithTheCodeCommandNotExists(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-not-exists', 400)]);

        try {
            $h->client->fetchCreatePackageCommand(self::CREATE);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'));
        }
    }

    public function testACreationThatEndsInABrowserIsReadWithItsRedirectUrl(): void
    {
        // Built from the specification (CreatePackageCommandGetResponse): the sandbox never redirected.
        $h = new ClientHarness([FakeTransport::jsonResponse('{"status":"successful","errors":[],"uuid":"' . self::CREATE . '","package_id":null,"redirect_url":"https://furgonetka.pl/potwierdzenie/logo"}')]);

        $result = $h->client->fetchCreatePackageCommand(self::CREATE);

        self::assertTrue($result->needsRedirect());
        self::assertNull($result->packageId);
    }

    // -- Uploading a file -----------------------------------------------------------------------------

    public function testAFileIsUploadedAsMultipartWithTheTokenAndReadUntilItIsStored(): void
    {
        $h = new ClientHarness([Fixtures::response('files/post-accepted'), Fixtures::response('files/queueing'), Fixtures::response('files/successful')]);

        $result = $h->client->uploadFile('invoice.pdf', self::PDF, 'invoice', uuid: self::UPLOAD);

        self::assertTrue($result->isSuccessful());
        self::assertSame('426a16fb-a37f-4a82-b0f1-e85705d64df6', $result->fileUuid);
        self::assertSame(
            ['POST ' . self::HOST . '/file-command/' . self::UPLOAD, 'GET ' . self::HOST . '/file-command?uuid%5B0%5D=' . self::UPLOAD, 'GET ' . self::HOST . '/file-command?uuid%5B0%5D=' . self::UPLOAD],
            $h->sent(),
        );

        $upload = $h->transport->requests[0];
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $upload->header('Authorization'), 'Sent although the specification lists no security for it.');
        self::assertSame('application/json', $upload->header('Accept'));
        self::assertStringStartsWith('multipart/form-data; boundary=furgonetka-', (string) $upload->header('Content-Type'));
        self::assertStringContainsString("filename=\"invoice.pdf\"\r\nContent-Type: application/pdf\r\n\r\n" . self::PDF . "\r\n", (string) $upload->body);
        self::assertStringContainsString("name=\"type\"\r\n\r\ninvoice\r\n", (string) $upload->body);
        self::assertSame([1.0], $h->sleeper->sleeps);
        self::assertSame('application/json', $h->transport->requests[1]->header('Accept'));
    }

    public function testTheMediaTypeComesFromTheExtensionUnlessGiven(): void
    {
        $h = new ClientHarness(array_fill(0, 7, Fixtures::response('files/post-accepted')));

        $sent = 0;

        foreach (['a.pdf' => 'application/pdf', 'a.PNG' => 'image/png', 'a.jpg' => 'image/jpeg', 'a.jpeg' => 'image/jpeg', 'a.gif' => 'image/gif', 'a.webp' => 'image/webp', 'a.txt' => 'text/plain'] as $name => $type) {
            $h->client->submitFileUpload($name, 'x');
            self::assertStringContainsString("Content-Type: {$type}\r\n", (string) $h->transport->requests[$sent++]->body, $name);
        }
    }

    public function testAnUnknownExtensionIsSentAsBinaryAndAGivenTypeWins(): void
    {
        $h = new ClientHarness([Fixtures::response('files/post-accepted'), Fixtures::response('files/post-accepted'), Fixtures::response('files/post-accepted')]);

        $h->client->submitFileUpload('a.xyz', 'x');
        $h->client->submitFileUpload('a', 'x');
        $h->client->submitFileUpload('a.pdf', 'x', contentType: ' image/tiff ');

        self::assertStringContainsString("Content-Type: application/octet-stream\r\n", (string) $h->transport->requests[0]->body);
        self::assertStringContainsString("Content-Type: application/octet-stream\r\n", (string) $h->transport->requests[1]->body);
        self::assertStringContainsString("Content-Type: image/tiff\r\n", (string) $h->transport->requests[2]->body);
    }

    public function testTheTypeIsLeftOutWhenBlank(): void
    {
        $h = new ClientHarness([Fixtures::response('files/post-accepted'), Fixtures::response('files/post-accepted')]);

        $h->client->submitFileUpload('a.pdf', 'x');
        $h->client->submitFileUpload('a.pdf', 'x', ' ');

        self::assertStringNotContainsString('name="type"', (string) $h->transport->requests[0]->body);
        self::assertStringNotContainsString('name="type"', (string) $h->transport->requests[1]->body);
    }

    /** @return iterable<string, array{callable(FurgonetkaApiClient): mixed, string}> */
    public static function badUploads(): iterable
    {
        yield 'an empty file' => [static fn (FurgonetkaApiClient $c) => $c->submitFileUpload('a.pdf', ''), 'must not be empty'];
        yield 'a media type that is not one' => [static fn (FurgonetkaApiClient $c) => $c->submitFileUpload('a.pdf', 'x', contentType: 'pdf'), 'content type'];
        yield 'a malformed uuid' => [static fn (FurgonetkaApiClient $c) => $c->submitFileUpload('a.pdf', 'x', uuid: 'nope'), 'UUID'];
        yield 'reading no uploads' => [static fn (FurgonetkaApiClient $c) => $c->fetchFileUploads([]), 'At least one'];
        yield 'reading 51 uploads' => [static fn (FurgonetkaApiClient $c) => $c->fetchFileUploads(array_fill(0, 51, self::UPLOAD)), 'At most 50'];
        yield 'reading a malformed uuid' => [static fn (FurgonetkaApiClient $c) => $c->fetchFileUploads([self::UPLOAD, 'nope']), 'UUID'];
        yield 'reading one malformed uuid' => [static fn (FurgonetkaApiClient $c) => $c->fetchFileUpload("../file-command\n"), 'UUID'];
    }

    /** @param callable(FurgonetkaApiClient): mixed $call */
    #[DataProvider('badUploads')]
    public function testBadInputIsRefusedBeforeAnythingIsSent(callable $call, string $message): void
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

    public function testFiftyUploadsMayBeReadAtOnce(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"responses":[]}')]);

        $h->client->fetchFileUploads(array_fill(0, 50, self::UPLOAD));

        self::assertSame(50, substr_count($h->transport->requests[0]->url, 'uuid%5B'));
    }

    public function testARetryWithTheSameUuidReadsTheUploadInsteadOfSendingTheFileAgain(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400)]);

        self::assertSame(self::UPLOAD, $h->client->submitFileUpload('a.pdf', 'x', uuid: strtoupper(self::UPLOAD)));
        self::assertSame(1, count($h->transport->requests));
    }

    public function testACommandExistsAnswerToAGeneratedUploadUuidIsAnError(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-exists', 400)]);

        $this->expectException(FurgonetkaApiBadRequestException::class);

        $h->client->submitFileUpload('a.pdf', 'x');
    }

    public function testANetworkFailureOnUploadCarriesTheUuid(): void
    {
        $h = new ClientHarness([new FurgonetkaApiTransportException('Operation timed out')]);

        try {
            $h->client->submitFileUpload('a.pdf', 'x');
            self::fail('Expected a transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid());
        }
    }

    public function testARefusedTokenRebuildsTheUploadWithTheNewOne(): void
    {
        $provider = new class implements TokenProviderInterface {
            public int $invalidated = 0;

            public function accessToken(): string
            {
                return $this->invalidated === 0 ? 'old-token' : 'new-token';
            }

            public function invalidate(): void
            {
                ++$this->invalidated;
            }
        };
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401), Fixtures::response('files/post-accepted')], $provider);

        $h->client->submitFileUpload('a.pdf', self::PDF, uuid: self::UPLOAD);

        self::assertSame(1, $provider->invalidated);
        self::assertSame(['Bearer old-token', 'Bearer new-token'], array_map(static fn ($r): ?string => $r->header('Authorization'), $h->transport->requests));
        self::assertSame($h->transport->requests[0]->url, $h->transport->requests[1]->url);
        self::assertStringContainsString(self::PDF, (string) $h->transport->requests[1]->body, 'The file goes out again, whole.');
        self::assertNotSame($h->transport->requests[0]->header('Content-Type'), $h->transport->requests[1]->header('Content-Type'), 'A new boundary for the new body.');
    }

    public function testSeveralUploadsAreReadInOneCallAndComeKeyedByUuid(): void
    {
        $h = new ClientHarness([Fixtures::response('files/two-unordered')]);

        $results = $h->client->fetchFileUploads(['FC061091-EE58-4605-9DB4-D62DCDA8B855', self::UPLOAD]);

        self::assertSame(
            self::HOST . '/file-command?uuid%5B0%5D=fc061091-ee58-4605-9db4-d62dcda8b855&uuid%5B1%5D=' . self::UPLOAD,
            $h->transport->requests[0]->url,
            'Lower-cased, in the order given.',
        );
        self::assertSame(['fc061091-ee58-4605-9db4-d62dcda8b855', self::UPLOAD], array_keys($results), 'In the order Furgonetka answered, which is not the order asked.');
        self::assertFalse($results['fc061091-ee58-4605-9db4-d62dcda8b855']->isFinished());
        self::assertTrue($results[self::UPLOAD]->isSuccessful());
    }

    public function testOneUuidTheAccountDoesNotKnowFailsTheWholeRead(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-file-unknown-uuid', 400)]);

        try {
            $h->client->fetchFileUploads([self::UPLOAD, self::CREATE]);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('valueTypeNotValid'));
            self::assertSame(['/uuid'], $e->getFieldPaths());
        }
    }

    public function testAnAnswerThatLeavesOutTheUploadIsAParseError(): void
    {
        $h = new ClientHarness([FakeTransport::jsonResponse('{"responses":[]}')]);

        $this->expectException(FurgonetkaApiResponseParseException::class);
        $this->expectExceptionMessage('does not contain it');

        $h->client->fetchFileUpload(self::UPLOAD);
    }

    public function testAnUploadThatNeverFinishesEndsInATimeoutThatCarriesTheUuid(): void
    {
        $queueing = Fixtures::response('files/queueing');
        $h = new ClientHarness([Fixtures::response('files/post-accepted'), $queueing, $queueing, $queueing, $queueing], polling: new PollingPolicy(timeout: 3.0));

        try {
            $h->client->uploadFile('a.pdf', 'x', uuid: self::UPLOAD);
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame(self::UPLOAD, $e->commandUuid());
        }
    }

    public function testAFailedUploadIsAResultNotAnException(): void
    {
        $failed = '{"responses":[{"uuid":"' . self::UPLOAD . '","status":"error","datetime_change":"2026-09-29T10:15:25+02:00","errors":[{"path":"/file","message":"The file is too large","details":"","code":"tooLarge"}]}]}';
        $h = new ClientHarness([Fixtures::response('files/post-accepted'), FakeTransport::jsonResponse($failed)]);

        $result = $h->client->uploadFile('a.pdf', 'x', uuid: self::UPLOAD);

        self::assertTrue($result->hasFailed());
        self::assertNull($result->fileUuid);
        self::assertSame('tooLarge', $result->errors[0]->code);
    }

    // -- The file is not a secret to Furgonetka but it is to everyone else ---------------------------------

    public function testTheContentsOfAFileAreNotInTheTraceOfAFailedUploadNorInTheLog(): void
    {
        $secret = 'invoice-secret-';
        self::assertSame(15, strlen($secret), 'A trace cuts string arguments to 15 characters, so a shorter secret could never show up.');
        self::assertSame('0', (string) ini_get('zend.exception_ignore_args'), 'phpunit.xml keeps the trace arguments, so this test can fail.');
        $h = new ClientHarness([new TransportResponse(400, '{"errors":[{"path":"/file","message":"Bad file","details":"","code":"invalid"}]}', ['content-type' => 'application/json'])]);

        try {
            $h->client->uploadFile('a.pdf', $secret);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            $trace = print_r($e->getTrace(), true);

            self::assertStringNotContainsString($secret, $trace);
            self::assertStringContainsString('SensitiveParameterValue', $trace, 'The argument is there, masked.');
        }

        self::assertStringNotContainsString($secret, $h->logger->dump());
    }

    public function testTheContentsOfAFileAreNotInTheTraceWhenTheNetworkFails(): void
    {
        $secret = 'invoice-secret-';
        // No scripted answer: FakeTransport throws from inside send(), so the trace holds the client's own
        // frames. An exception built in the test would carry the test's stack and prove nothing.
        $h = new ClientHarness();

        try {
            $h->client->submitFileUpload('a.pdf', $secret);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertStringNotContainsString($secret, print_r($e->getTrace(), true));
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }
}
