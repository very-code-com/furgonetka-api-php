<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiGoneException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiHttpException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiRateLimitException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Internal\Http\ErrorMapper;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * The error bodies here are real: captured on the sandbox on 2026-09-28 and 2026-09-29, kept in
 * tests/Fixtures/errors. They show what the documentation does not: that a shipment of someone
 * else answers 400 and not 404, and that the same 404 has an empty `code`.
 */
final class ErrorMapperTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../Fixtures/errors/' . $name . '.json');
    }

    /** @param non-empty-string $method */
    private function request(string $method = 'GET', string $url = 'https://api.sandbox.furgonetka.pl/packages/1'): TransportRequest
    {
        return new TransportRequest($url, $method, $method === 'GET' ? null : '{}');
    }

    /** @param array<string, string> $headers */
    private function map(int $status, string $body, array $headers = [], ?TransportRequest $request = null): FurgonetkaApiHttpException
    {
        return (new ErrorMapper())->map($request ?? $this->request(), new TransportResponse($status, $body, $headers));
    }

    // -- Status to exception ----------------------------------------------------

    /**
     * @return iterable<string, array{int, class-string<FurgonetkaApiHttpException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, FurgonetkaApiBadRequestException::class];
        yield '401' => [401, FurgonetkaApiAuthException::class];
        yield '403' => [403, FurgonetkaApiAuthException::class];
        yield '404' => [404, FurgonetkaApiNotFoundException::class];
        yield '409' => [409, FurgonetkaApiConflictException::class];
        yield '410' => [410, FurgonetkaApiGoneException::class];
        yield '429' => [429, FurgonetkaApiRateLimitException::class];
        yield '500' => [500, FurgonetkaApiServerException::class];
        yield '502' => [502, FurgonetkaApiServerException::class];
        yield '503' => [503, FurgonetkaApiServerException::class];
        yield '504' => [504, FurgonetkaApiServerException::class];
        yield '599' => [599, FurgonetkaApiServerException::class];
        yield '402 has no class of its own' => [402, FurgonetkaApiHttpException::class];
        yield '405' => [405, FurgonetkaApiHttpException::class];
        yield '422' => [422, FurgonetkaApiHttpException::class];
        yield 'a redirect is not followed and is reported' => [302, FurgonetkaApiHttpException::class];
    }

    /**
     * @param class-string<FurgonetkaApiHttpException> $class
     */
    #[DataProvider('statuses')]
    public function testEachStatusGivesTheNarrowestException(int $status, string $class): void
    {
        $exception = $this->map($status, '');

        self::assertSame($class, $exception::class);
        self::assertSame($status, $exception->statusCode);
        self::assertSame($status, $exception->getCode());
    }

    public function testStatusesWithTheirOwnClassAreNotMistakenForTheBaseClass(): void
    {
        self::assertNotSame(FurgonetkaApiHttpException::class, $this->map(400, '')::class);
        self::assertNotSame(FurgonetkaApiHttpException::class, $this->map(500, '')::class);
    }

    // -- The errors[] shape ------------------------------------------------------

    public function testTheErrorsShapeIsReadIntoApiErrors(): void
    {
        $exception = $this->map(400, $this->fixture('bad-request-validation'), request: $this->request('POST', 'https://api.sandbox.furgonetka.pl/packages/validate'));

        self::assertCount(3, $exception->errors);
        self::assertSame('/receiver/postcode', $exception->errors[0]->path);
        self::assertSame('format', $exception->errors[0]->code);
        self::assertSame("Acceptable postcode format for the selected country is '99-999'", $exception->errors[0]->message);
        self::assertSame('/parcels/0/weight', $exception->errors[2]->path);
        self::assertNull($exception->errors[0]->details, 'The empty details Furgonetka sends carry no information.');
        self::assertSame(['/receiver/postcode', '/receiver/city', '/parcels/0/weight'], $exception->getFieldPaths());
        self::assertTrue($exception->hasErrorCode('notExists'));
    }

    public function testTheMessageNamesTheRequestTheStatusAndTheFirstError(): void
    {
        $exception = $this->map(400, $this->fixture('bad-request-validation'), request: $this->request('POST', 'https://api.sandbox.furgonetka.pl/packages/validate'));

        self::assertSame(
            "Furgonetka API POST https://api.sandbox.furgonetka.pl/packages/validate failed (HTTP 400): "
            . "Acceptable postcode format for the selected country is '99-999' [format] (/receiver/postcode) (and 2 more)",
            $exception->getMessage(),
        );
    }

    public function testASingleErrorHasNoAndMore(): void
    {
        $exception = $this->map(400, $this->fixture('bad-request-service-missing'));

        self::assertStringEndsWith('Please choose an offer [isEmpty] (/service_id)', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{int, string, class-string<FurgonetkaApiHttpException>, string}>
     */
    public static function realErrors(): iterable
    {
        yield 'a shipment of someone else is a 400' => [400, 'bad-request-not-owner', FurgonetkaApiBadRequestException::class, 'packageOwner'];
        yield 'an unknown command is a 400' => [400, 'bad-request-command-not-exists', FurgonetkaApiBadRequestException::class, 'commandNotExists'];
        yield 'an unknown shipment is a 404 with an empty code' => [404, 'not-found-package', FurgonetkaApiNotFoundException::class, ''];
    }

    /**
     * @param class-string<FurgonetkaApiHttpException> $class
     */
    #[DataProvider('realErrors')]
    public function testRealErrorsOfTheSandbox(int $status, string $fixture, string $class, string $code): void
    {
        $exception = $this->map($status, $this->fixture($fixture));

        self::assertSame($class, $exception::class);
        self::assertCount(1, $exception->errors);
        self::assertSame($code === '' ? null : $code, $exception->errors[0]->code);
        self::assertSame('/' . (str_contains($fixture, 'command') ? 'uuid' : 'package_id'), $exception->errors[0]->path);
    }

    public function testAnErrorsEntryWithoutAnyFieldIsStillAnEntry(): void
    {
        $exception = $this->map(400, '{"errors":[{}]}');

        self::assertCount(1, $exception->errors);
        self::assertStringEndsWith('Unknown error', $exception->getMessage());
    }

    // -- The flat 401 shape -------------------------------------------------------

    public function testTheFlatShapeBecomesOneErrorWithoutAPath(): void
    {
        $exception = $this->map(401, $this->fixture('unauthorized-missing-header'));

        self::assertInstanceOf(FurgonetkaApiAuthException::class, $exception);
        self::assertCount(1, $exception->errors);
        self::assertSame('access_denied', $exception->errors[0]->code);
        self::assertNull($exception->errors[0]->path);
        self::assertSame('Missing "Authorization" header', $exception->errors[0]->details);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unauthorized(): iterable
    {
        yield 'no header' => ['unauthorized-missing-header', 'Hint: no access token was sent.'];
        yield 'a token of the other environment' => ['unauthorized-bad-token', 'Hint: the access token is not valid here. It may belong to the other environment'];
        yield 'an application token' => ['unauthorized-application-token', 'Hint: the token belongs to no user.'];
    }

    #[DataProvider('unauthorized')]
    public function testA401ExplainsItselfFromTheDetails(string $fixture, string $hint): void
    {
        $exception = $this->map(401, $this->fixture($fixture));

        self::assertStringContainsString($hint, $exception->getMessage());
    }

    public function testAn401WeDoNotRecogniseGetsNoHint(): void
    {
        $exception = $this->map(401, '{"code":"access_denied","message":"Something new","details":"A reason we have never seen"}');

        self::assertStringNotContainsString('Hint:', $exception->getMessage());
        self::assertStringContainsString('Something new', $exception->getMessage());
    }

    public function testTheHintIsForA401Only(): void
    {
        $exception = $this->map(403, $this->fixture('unauthorized-missing-header'));

        self::assertStringNotContainsString('Hint:', $exception->getMessage());
    }

    // -- Rate limit ------------------------------------------------------------

    public function testARateLimitCarriesTheCoolDown(): void
    {
        $exception = $this->map(429, '{"errors":[{"message":"Too Many Requests"}]}', ['X-RateLimit-Retry-After' => '1800']);

        self::assertInstanceOf(FurgonetkaApiRateLimitException::class, $exception);
        self::assertSame(1800, $exception->retryAfterSeconds());
        self::assertStringEndsWith('Do not retry for 1800 seconds.', $exception->getMessage());
    }

    public function testARateLimitWithoutACoolDownSaysSo(): void
    {
        $exception = $this->map(429, '');

        self::assertInstanceOf(FurgonetkaApiRateLimitException::class, $exception);
        self::assertNull($exception->retryAfterSeconds());
        self::assertStringEndsWith('The request limit is used up.', $exception->getMessage());
    }

    public function testAZeroCoolDownMeansNothingToWaitFor(): void
    {
        $exception = $this->map(429, '', ['X-RateLimit-Retry-After' => '0']);

        self::assertInstanceOf(FurgonetkaApiRateLimitException::class, $exception);
        self::assertNull($exception->retryAfterSeconds());
    }

    // -- Server errors and bodies that are not JSON ------------------------------

    public function testAServerErrorKnowsWhetherItIsWorthRetrying(): void
    {
        $gateway = $this->map(502, '');
        $plain = $this->map(500, $this->fixture('not-found-package'));

        self::assertInstanceOf(FurgonetkaApiServerException::class, $gateway);
        self::assertInstanceOf(FurgonetkaApiServerException::class, $plain);
        self::assertTrue($gateway->isRetryable());
        self::assertFalse($plain->isRetryable());
    }

    /**
     * @return iterable<string, array{string, non-empty-string}> body, what the message must end with
     */
    public static function bodiesThatAreNotJson(): iterable
    {
        yield 'an HTML page' => ['<html lang="en"><body><h1>502 Bad Gateway</h1><p>nginx</p></body></html>', '502 Bad Gateway nginx'];
        yield 'plain text' => ["upstream   timed out\n", 'upstream timed out'];
        yield 'control characters are dropped' => ["a\x00b\x1Bc", 'a b c'];
        yield 'a body of tags only' => ['<br><br>', '(no readable text)'];
        yield 'invalid UTF-8' => ["\xFF\xFE\xFD", '(no readable text)'];
        yield 'a JSON value that is not an object' => ['"just a string"', '"just a string"'];
    }

    /**
     * @param non-empty-string $ending
     */
    #[DataProvider('bodiesThatAreNotJson')]
    public function testABodyThatIsNotAnErrorObjectStillGivesAReadableMessage(string $body, string $ending): void
    {
        $exception = $this->map(502, $body);

        self::assertSame([], $exception->errors);
        self::assertStringEndsWith($ending, $exception->getMessage());
        self::assertStringContainsString('(HTTP 502): ', $exception->getMessage());
    }

    public function testALongBodyIsCutToAnExcerpt(): void
    {
        $exception = $this->map(502, str_repeat('word ', 200));

        self::assertLessThan(400, strlen($exception->getMessage()));
        self::assertStringEndsWith('...', $exception->getMessage());
    }

    public function testAnEmptyBodyGivesTheStatusOnly(): void
    {
        $exception = $this->map(503, '   ');

        self::assertStringEndsWith('failed (HTTP 503).', $exception->getMessage());
    }

    public function testAJsonObjectWithoutErrorFieldsIsNotQuotedNorMisreadAsAnError(): void
    {
        $exception = $this->map(400, '{"status":"ERROR","result":null,"errors":{"packageParams":{"isEmpty":"The field cannot be empty."}},"params":[]}');

        self::assertSame([], $exception->errors, 'The legacy map-shaped "errors" is not the errors[] list.');
        self::assertStringEndsWith('failed (HTTP 400).', $exception->getMessage());
    }

    // -- 409: the regulations ------------------------------------------------------

    public function testTheRealConflictBodyGetsAHintToTheTwoCallsThatSolveIt(): void
    {
        $request = $this->request('PUT', 'https://api.sandbox.furgonetka.pl/order-commands/45b65793-f50d-4edb-b09f-5ff3bcd14abf');

        $exception = $this->map(409, $this->fixture('conflict-terms-and-conditions'), request: $request);

        self::assertInstanceOf(FurgonetkaApiConflictException::class, $exception);
        self::assertTrue($exception->hasErrorCode('terms_and_conditions_not_valid'));
        self::assertStringContainsString('pendingRegulations()', $exception->getMessage());
        self::assertStringContainsString('acceptRegulations()', $exception->getMessage());
        self::assertStringContainsString('never does that for you', $exception->getMessage());
        self::assertStringContainsString("The carrier's regulations have changed", $exception->getMessage(), 'Furgonetka\'s own words stay.');
    }

    public function testAConflictWithAnotherCodeGetsNoRegulationsHint(): void
    {
        $exception = $this->map(409, '{"code":"something_else","message":"Conflict","details":""}');

        self::assertInstanceOf(FurgonetkaApiConflictException::class, $exception);
        self::assertStringNotContainsString('pendingRegulations()', $exception->getMessage());
    }

    public function testTheHintIsOnlyForA409(): void
    {
        $exception = $this->map(400, $this->fixture('conflict-terms-and-conditions'));

        self::assertStringNotContainsString('pendingRegulations()', $exception->getMessage());
    }

    // -- What the mapper does not do ---------------------------------------------

    public function testTheRawBodyIsNeverAttached(): void
    {
        $exception = $this->map(400, $this->fixture('bad-request-validation'));

        self::assertNull($exception->getRawResponse());
    }

    public function testTheQueryStringOfTheRequestStaysOutOfTheMessage(): void
    {
        $exception = $this->map(400, '', request: $this->request('GET', 'https://api.sandbox.furgonetka.pl/packages?query=secret-name&limit=1'));

        self::assertStringContainsString('GET https://api.sandbox.furgonetka.pl/packages failed', $exception->getMessage());
        self::assertStringNotContainsString('secret-name', $exception->getMessage());
    }
}
