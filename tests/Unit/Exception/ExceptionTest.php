<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\ApiError;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiGoneException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiHttpException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiLabelNotReadyException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiRateLimitException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTwoFactorRequiredException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;

final class ExceptionTest extends TestCase
{
    /**
     * One instance of every exception in the library.
     *
     * @return list<FurgonetkaApiException>
     */
    private static function everyException(): array
    {
        return [
            new FurgonetkaApiException('x'),
            new FurgonetkaApiValidationException(['x']),
            new FurgonetkaApiTransportException('x'),
            new FurgonetkaApiResponseParseException('x'),
            new FurgonetkaApiLabelNotReadyException('x'),
            new FurgonetkaApiCommandTimeoutException('x', 'uuid'),
            new FurgonetkaApiOAuthException('x'),
            new FurgonetkaApiTwoFactorRequiredException('x', 'hint'),
            new FurgonetkaApiReauthorizationRequiredException('x'),
            new FurgonetkaApiHttpException('x', 418),
            new FurgonetkaApiBadRequestException('x', 400),
            new FurgonetkaApiAuthException('x', 401),
            new FurgonetkaApiNotFoundException('x', 404),
            new FurgonetkaApiConflictException('x', 409),
            new FurgonetkaApiGoneException('x', 410),
            new FurgonetkaApiRateLimitException('x', 429),
            new FurgonetkaApiServerException('x', 500),
        ];
    }

    public function testEverythingDescendsFromTheBaseException(): void
    {
        foreach (self::everyException() as $exception) {
            self::assertInstanceOf(FurgonetkaApiException::class, $exception);
            self::assertInstanceOf(\RuntimeException::class, $exception);
        }
    }

    public function testTheHttpSubtypesAreAllHttpExceptions(): void
    {
        foreach ([
            new FurgonetkaApiBadRequestException('x', 400),
            new FurgonetkaApiAuthException('x', 401),
            new FurgonetkaApiNotFoundException('x', 404),
            new FurgonetkaApiConflictException('x', 409),
            new FurgonetkaApiGoneException('x', 410),
            new FurgonetkaApiRateLimitException('x', 429),
            new FurgonetkaApiServerException('x', 502),
        ] as $exception) {
            self::assertInstanceOf(FurgonetkaApiHttpException::class, $exception);
        }
    }

    /**
     * @return iterable<string, array{FurgonetkaApiException}>
     */
    public static function nonHttpExceptions(): iterable
    {
        yield 'validation' => [new FurgonetkaApiValidationException(['x'])];
        yield 'transport' => [new FurgonetkaApiTransportException('x')];
        yield 'response parse' => [new FurgonetkaApiResponseParseException('x')];
        yield 'label not ready' => [new FurgonetkaApiLabelNotReadyException('x')];
        yield 'command timeout' => [new FurgonetkaApiCommandTimeoutException('x', 'uuid')];
        yield 'oauth' => [new FurgonetkaApiOAuthException('x')];
        yield 'two factor' => [new FurgonetkaApiTwoFactorRequiredException('x', 'hint')];
        yield 'reauthorization' => [new FurgonetkaApiReauthorizationRequiredException('x')];
    }

    #[DataProvider('nonHttpExceptions')]
    public function testEverythingThatIsNotAnHttpAnswerIsNotAnHttpException(FurgonetkaApiException $exception): void
    {
        // A caller that catches FurgonetkaApiHttpException is handling "the API said no", which a
        // timeout, an unreadable body, a failed local check or a token endpoint refusal is not.
        // The parameter is typed as the base class, so this stays a real check of the hierarchy.
        self::assertNotInstanceOf(FurgonetkaApiHttpException::class, $exception);
    }

    public function testTheOAuthExceptionsFormTheirOwnBranch(): void
    {
        self::assertInstanceOf(FurgonetkaApiOAuthException::class, new FurgonetkaApiTwoFactorRequiredException('x', 'h'));
        self::assertInstanceOf(FurgonetkaApiOAuthException::class, new FurgonetkaApiReauthorizationRequiredException('x'));
    }

    // -- Validation ---------------------------------------------------------------

    public function testValidationExceptionListsEveryError(): void
    {
        $exception = new FurgonetkaApiValidationException(['receiver.name is required', 'parcels must not be empty']);

        self::assertSame(['receiver.name is required', 'parcels must not be empty'], $exception->errors);
        self::assertStringContainsString('receiver.name is required; parcels must not be empty', $exception->getMessage());
    }

    // -- Transport ----------------------------------------------------------------

    public function testTransportExceptionCanCarryTheCommandUuid(): void
    {
        $exception = new FurgonetkaApiTransportException('timeout');

        self::assertNull($exception->commandUuid());
        self::assertSame($exception, $exception->withCommandUuid('7b0c3a9e-0000-4000-8000-000000000001'));
        self::assertSame('7b0c3a9e-0000-4000-8000-000000000001', $exception->commandUuid());
        self::assertNull($exception->withCommandUuid(null)->commandUuid());
    }

    // -- Command timeout ----------------------------------------------------------

    public function testCommandTimeoutKeepsTheUuidAndTheLastStatusAsSent(): void
    {
        $cause = new \RuntimeException('poll');
        $exception = new FurgonetkaApiCommandTimeoutException('gave up', 'the-uuid', 'running', $cause);

        self::assertSame('the-uuid', $exception->commandUuid());
        self::assertSame('running', $exception->lastStatus());
        self::assertSame($cause, $exception->getPrevious());
        self::assertNull((new FurgonetkaApiCommandTimeoutException('gave up', 'the-uuid'))->lastStatus());
    }

    // -- OAuth --------------------------------------------------------------------

    public function testOAuthExceptionExposesTheRfc6749Fields(): void
    {
        $exception = new FurgonetkaApiOAuthException('login failed', 'invalid_grant', 'The user credentials were incorrect.', 400);

        self::assertSame('invalid_grant', $exception->error);
        self::assertSame('The user credentials were incorrect.', $exception->errorDescription);
        self::assertSame(400, $exception->statusCode);
        self::assertSame(400, $exception->getCode());
    }

    public function testOAuthExceptionFieldsDefaultToEmpty(): void
    {
        $exception = new FurgonetkaApiReauthorizationRequiredException('reconnect the account');

        self::assertSame('', $exception->error);
        self::assertSame('', $exception->errorDescription);
        self::assertSame(0, $exception->statusCode);
    }

    public function testTwoFactorExceptionCarriesTheHint(): void
    {
        $exception = new FurgonetkaApiTwoFactorRequiredException('2FA', '0d29d8ab-54da-49b6-b05f-d92b2a15ed0e', 'Account has two-step login.', 400);

        self::assertSame('0d29d8ab-54da-49b6-b05f-d92b2a15ed0e', $exception->hint());
        self::assertSame('2fa_required', $exception->error);
        self::assertSame('Account has two-step login.', $exception->errorDescription);
        self::assertSame(400, $exception->statusCode);
    }

    // -- HTTP ---------------------------------------------------------------------

    public function testHttpExceptionCollectsFieldPathsWithoutRepeats(): void
    {
        $exception = new FurgonetkaApiBadRequestException('bad', 400, [
            new ApiError('Invalid', 'invalidValue', '/receiver/postcode'),
            new ApiError('Required', 'required', '/pickup/street'),
            new ApiError('Too long', 'tooLong', '/receiver/postcode'),
            new ApiError('No path at all'),
        ]);

        self::assertSame(['/receiver/postcode', '/pickup/street'], $exception->getFieldPaths());
        self::assertSame([], (new FurgonetkaApiHttpException('x', 500))->getFieldPaths());
    }

    public function testHttpExceptionFindsAnErrorCode(): void
    {
        $exception = new FurgonetkaApiHttpException('x', 500, [new ApiError(code: 'carrierValidationError', path: '/packages/id/1')]);

        self::assertTrue($exception->hasErrorCode('carrierValidationError'));
        self::assertFalse($exception->hasErrorCode('unknownError'));
        self::assertFalse((new FurgonetkaApiHttpException('x', 500))->hasErrorCode('carrierValidationError'));
    }

    public function testHttpExceptionKeepsStatusAndCode(): void
    {
        $exception = new FurgonetkaApiNotFoundException('missing', 404);

        self::assertSame(404, $exception->statusCode);
        self::assertSame(404, $exception->getCode());
    }

    public function testRateLimitExceptionCarriesTheCooldown(): void
    {
        $exception = new FurgonetkaApiRateLimitException('slow down', 429);

        self::assertNull($exception->retryAfterSeconds());
        self::assertSame($exception, $exception->withRetryAfter(1800));
        self::assertSame(1800, $exception->retryAfterSeconds());
        self::assertNull($exception->withRetryAfter(0)->retryAfterSeconds(), 'Zero means nothing to wait for.');
        self::assertNull($exception->withRetryAfter(-3)->retryAfterSeconds());
        self::assertNull($exception->withRetryAfter(null)->retryAfterSeconds());
    }

    public function testOnlyGatewayFailuresAreRetryable(): void
    {
        foreach ([502, 503, 504] as $status) {
            self::assertTrue((new FurgonetkaApiServerException('x', $status))->isRetryable(), (string) $status);
        }
        foreach ([500, 501, 505] as $status) {
            self::assertFalse((new FurgonetkaApiServerException('x', $status))->isRetryable(), (string) $status);
        }
    }

    // -- Debug report -------------------------------------------------------------

    public function testTheDebugReportOfASubclassNamesTheSubclass(): void
    {
        $exception = (new FurgonetkaApiBadRequestException('bad payload', 400))
            ->withRawResponse('{"errors":[{"path":"/receiver/postcode"}]}');

        $report = $exception->getDebugReport();

        self::assertStringContainsString('FurgonetkaApiBadRequestException: bad payload', $report);
        self::assertStringContainsString('/receiver/postcode', $report);
    }
}
