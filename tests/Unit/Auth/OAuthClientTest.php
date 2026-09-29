<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Enum\Language;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTwoFactorRequiredException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\RecordingLogger;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

final class OAuthClientTest extends TestCase
{
    private function client(FakeTransport $transport, ?FurgonetkaApiConfig $config = null, ?RecordingLogger $logger = null): OAuthClient
    {
        return new OAuthClient(
            $config ?? TokenFactory::config(),
            $transport,
            $logger,
            static fn (): \DateTimeImmutable => TokenFactory::now(),
        );
    }

    private function answering(TransportResponse $response): FakeTransport
    {
        return new FakeTransport([$response]);
    }

    public function testItNeedsTheApplicationKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the OAuth application keys');

        new OAuthClient(FurgonetkaApiConfig::sandbox(), new FakeTransport());
    }

    // -- Authorization code ----------------------------------------------------

    public function testAuthorizationUrlPointsAtTheConsentPageOfTheEnvironment(): void
    {
        $transport = new FakeTransport();

        $sandbox = $this->client($transport)->authorizationUrl();
        $production = $this->client($transport, TokenFactory::config(sandbox: false))->authorizationUrl();

        self::assertStringStartsWith('https://api.sandbox.furgonetka.pl/oauth/authorize?', $sandbox->url);
        self::assertStringStartsWith('https://api.furgonetka.pl/oauth/authorize?', $production->url);
        self::assertSame([], $transport->requests, 'Building the URL must not send anything.');
    }

    public function testAuthorizationUrlCarriesTheParametersFurgonetkaDocuments(): void
    {
        $request = $this->client(new FakeTransport())->authorizationUrl();

        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

        self::assertSame([
            'response_type' => 'code',
            'client_id' => TokenFactory::CLIENT_ID,
            'redirect_uri' => TokenFactory::REDIRECT_URI,
            'state' => $request->state,
        ], $query);
        self::assertStringContainsString('redirect_uri=http%3A%2F%2Flocalhost%3A8080%2Foauth%2Fcallback', $request->url);
    }

    public function testEveryAuthorizationUrlGetsAFreshUnpredictableState(): void
    {
        $client = $this->client(new FakeTransport());

        $first = $client->authorizationUrl()->state;
        $second = $client->authorizationUrl()->state;

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function testAuthorizationUrlNeedsARedirectUri(): void
    {
        $config = FurgonetkaApiConfig::sandbox(TokenFactory::CLIENT_ID, TokenFactory::CLIENT_SECRET);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a redirect URI');

        $this->client(new FakeTransport(), $config)->authorizationUrl();
    }

    public function testTheAuthorizationCodeIsExchangedForATokenPair(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('token-authorization-code')));

        $token = $this->client($transport)->exchangeAuthorizationCode('the-code', 'state-1', 'state-1');

        self::assertSame('eyJ...aeg', $token->accessToken);
        self::assertSame('def...540', $token->refreshToken);
        self::assertSame(TokenFactory::now()->getTimestamp() + 2678400, $token->expiresAt->getTimestamp());
        self::assertSame('sandbox', $token->environment);
        self::assertSame(hash('sha256', TokenFactory::CLIENT_ID), $token->clientIdHash);

        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'redirect_uri' => TokenFactory::REDIRECT_URI,
        ], $transport->lastForm());
    }

    public function testTheCodeIsUrlDecodedBeforeItIsSent(): void
    {
        $transport = $this->answering(TokenFactory::tokenResponse());

        $this->client($transport)->exchangeAuthorizationCode('a%2Bb%3Dc', 's', 's');

        // Furgonetka's OAuth documentation: "Przed przesłaniem należy go zdekodować".
        self::assertSame('a+b=c', $transport->lastForm()['code']);
    }

    /**
     * @return iterable<string, array{string, string}> state of the callback, state that was kept
     */
    public static function refusedStates(): iterable
    {
        yield 'different states' => ['forged', 'kept'];
        yield 'a callback without state' => ['', 'kept'];
        yield 'case differs' => ['STATE', 'state'];
        yield 'both empty, the session was lost' => ['', ''];
    }

    #[DataProvider('refusedStates')]
    public function testACallbackWithTheWrongStateIsRefusedBeforeAnythingIsSent(string $state, string $expected): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->exchangeAuthorizationCode('the-code', $state, $expected);
            self::fail('Expected a state_mismatch.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('state_mismatch', $e->error);
            self::assertStringContainsString('The code was not used', $e->getMessage());
        }

        self::assertSame([], $transport->requests, 'A forged callback must not cost the code.');
    }

    public function testAnEmptyCodeIsRejectedLocally(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->exchangeAuthorizationCode(' ', 's', 's');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('authorization code must not be empty', $e->getMessage());
        }

        self::assertSame([], $transport->requests);
    }

    public function testTheExchangeNeedsARedirectUri(): void
    {
        $config = FurgonetkaApiConfig::sandbox(TokenFactory::CLIENT_ID, TokenFactory::CLIENT_SECRET);

        $this->expectException(\LogicException::class);

        $this->client(new FakeTransport(), $config)->exchangeAuthorizationCode('c', 's', 's');
    }

    // -- Password grant and 2FA ------------------------------------------------

    public function testThePasswordGrantSendsTheDocumentedRequest(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('token-password')));

        $token = $this->client($transport)->passwordGrant('shop@example.com', 'p@ss word');
        $request = $transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.sandbox.furgonetka.pl/oauth/token', $request->url);
        self::assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
        self::assertSame('Basic ' . base64_encode('client-1:secret-1'), $request->header('authorization'));
        self::assertSame('application/json', $request->header('accept'));
        self::assertSame('en_GB', $request->header('x-language'));
        self::assertSame([
            'grant_type' => 'password',
            'scope' => 'api',
            'username' => 'shop@example.com',
            'password' => 'p@ss word',
        ], $transport->lastForm());

        self::assertSame('eyJ...ygA', $token->accessToken);
        self::assertSame('def...ca2', $token->refreshToken);
        self::assertSame(TokenFactory::now()->getTimestamp() + 2588400, $token->expiresAt->getTimestamp());
    }

    public function testTheRequestTimeoutsAndLanguageComeFromTheConfig(): void
    {
        $config = new FurgonetkaApiConfig(
            credentials: TokenFactory::config()->credentials,
            sandbox: false,
            language: Language::Polish,
            timeout: 7,
            connectTimeout: 3,
        );
        $transport = $this->answering(TokenFactory::tokenResponse());

        $this->client($transport, $config)->clientCredentials();
        $request = $transport->lastRequest();

        self::assertSame('https://api.furgonetka.pl/oauth/token', $request->url);
        self::assertSame('pl_PL', $request->header('x-language'));
        self::assertSame(7, $request->timeout);
        self::assertSame(3, $request->connectTimeout);
    }

    public function testThePasswordGrantRejectsEmptyCredentialsLocally(): void
    {
        $client = $this->client(new FakeTransport());

        foreach ([['', 'pw', 'username'], ['  ', 'pw', 'username'], ['user', '', 'password']] as [$user, $password, $what]) {
            try {
                $client->passwordGrant($user, $password);
                self::fail("Expected the empty {$what} to be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("the {$what} must not be empty", $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function twoFactorStatuses(): iterable
    {
        // Furgonetka's documentation shows the body of this answer but not its status, so the
        // detection must rest on the error code alone.
        yield 'HTTP 400' => [400];
        yield 'HTTP 401' => [401];
    }

    #[DataProvider('twoFactorStatuses')]
    public function testAnAccountWithTwoFactorMakesThePasswordGrantAskForTheCode(int $status): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('error-2fa-required'), $status));

        try {
            $this->client($transport)->passwordGrant('shop@example.com', 'pw');
            self::fail('Expected a FurgonetkaApiTwoFactorRequiredException.');
        } catch (FurgonetkaApiTwoFactorRequiredException $e) {
            self::assertSame('0d29d8ab-54da-49b6-b05f-d92b2a15ed0e', $e->hint());
            self::assertSame('2fa_required', $e->error);
            self::assertSame('Konto ma włączone logowanie dwuetapowe.', $e->errorDescription);
            self::assertSame($status, $e->statusCode);
            self::assertStringContainsString('two-factor code', $e->getMessage());
        }
    }

    public function testTheTwoFactorRequestIsLoggedAsInformationNotAsAnError(): void
    {
        $logger = new RecordingLogger();
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('error-2fa-required'), 401));

        try {
            $this->client($transport, logger: $logger)->passwordGrant('shop@example.com', 'pw');
        } catch (FurgonetkaApiTwoFactorRequiredException) {
            // Expected; the log is what is being checked.
        }

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertStringContainsString('needs a two-factor code', $logger->records[0]['message']);
    }

    public function testATwoFactorAnswerWithoutAHintIsAPlainOAuthError(): void
    {
        $transport = $this->answering(TokenFactory::errorResponse('2fa_required', 'Two-step login is on.', 401));

        try {
            $this->client($transport)->passwordGrant('shop@example.com', 'pw');
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertNotInstanceOf(FurgonetkaApiTwoFactorRequiredException::class, $e);
            self::assertSame('2fa_required', $e->error);
        }
    }

    public function testTheTwoFactorLoginIsFinishedThroughTheTwoFactorEndpoint(): void
    {
        $transport = $this->answering(TokenFactory::tokenResponse('access-2fa', 'refresh-2fa'));

        $token = $this->client($transport)->completeTwoFactor('0d29d8ab-54da-49b6-b05f-d92b2a15ed0e', 'shop@example.com', '123456');
        $request = $transport->lastRequest();

        self::assertSame('https://api.sandbox.furgonetka.pl/oauth/2fa', $request->url);
        self::assertSame('POST', $request->method);
        self::assertSame('Basic ' . base64_encode('client-1:secret-1'), $request->header('authorization'));
        self::assertSame([
            'uuid' => '0d29d8ab-54da-49b6-b05f-d92b2a15ed0e',
            'identifier' => 'shop@example.com',
            'twoFactorCode' => '123456',
        ], $transport->lastForm());
        self::assertSame('access-2fa', $token->accessToken);
        self::assertSame('refresh-2fa', $token->refreshToken);
    }

    public function testTheTwoFactorLoginRejectsEmptyInputLocally(): void
    {
        $client = $this->client(new FakeTransport());

        foreach ([['', 'user', '123456'], ['uuid', ' ', '123456'], ['uuid', 'user', '']] as [$hint, $user, $code]) {
            try {
                $client->completeTwoFactor($hint, $user, $code);
                self::fail('Expected empty input to be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('must not be empty', $e->getMessage());
            }
        }
    }

    // -- Refresh and client credentials ----------------------------------------

    public function testARefreshTokenIsTradedForANewPair(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('token-refresh')));

        $token = $this->client($transport)->refresh('def...540');

        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'def...540'], $transport->lastForm());
        self::assertSame('eyJ...84c', $token->accessToken);
        self::assertSame('def...861', $token->refreshToken);
        self::assertSame(TokenFactory::now()->getTimestamp() + 2678400, $token->expiresAt->getTimestamp());
    }

    public function testARefreshWithAnEmptyTokenIsRejectedLocally(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->refresh('');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
        }
    }

    public function testARejectedRefreshTokenIsAnOAuthErrorNotAReauthorizationRequest(): void
    {
        // The body the sandbox sends for a refresh token that was already used (2026-09-28).
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('error-invalid-grant-refresh'), 400));

        try {
            $this->client($transport)->refresh('spent');
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            // Whether that means "connect again" is for the token manager to decide: the same
            // error from a password grant just means a wrong password.
            self::assertNotInstanceOf(FurgonetkaApiReauthorizationRequiredException::class, $e);
            self::assertSame('invalid_grant', $e->error);
            self::assertSame('The refresh token is invalid.', $e->errorDescription);
            self::assertSame(400, $e->statusCode);
            self::assertStringEndsWith('invalid_grant: The refresh token is invalid. (Token has been revoked)', $e->getMessage());
        }
    }

    public function testClientCredentialsGiveATokenThatCannotBeRefreshed(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('token-client-credentials')));

        $token = $this->client($transport)->clientCredentials();

        self::assertSame(['grant_type' => 'client_credentials', 'scope' => 'api'], $transport->lastForm());
        self::assertSame('eyJ...v6A', $token->accessToken);
        self::assertNull($token->refreshToken);
        self::assertSame(TokenFactory::now()->getTimestamp() + 3600, $token->expiresAt->getTimestamp());
    }

    // -- Errors ----------------------------------------------------------------

    public function testAnErrorAnswerBecomesAnOAuthExceptionWithTheRfcFields(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('error-invalid-client'), 401));

        try {
            $this->client($transport)->clientCredentials();
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('invalid_client', $e->error);
            self::assertSame('Client authentication failed', $e->errorDescription);
            self::assertSame(401, $e->statusCode);
            self::assertSame(
                'Furgonetka OAuth client credentials grant failed (HTTP 401): invalid_client: Client authentication failed',
                $e->getMessage(),
            );
        }
    }

    public function testWrongUserCredentialsAreAnInvalidGrant(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(TokenFactory::fixture('error-invalid-grant-credentials'), 400));

        try {
            $this->client($transport)->passwordGrant('shop@example.com', 'wrong');
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
            self::assertSame('The user credentials were incorrect.', $e->errorDescription);
        }
    }

    public function testTheExplanatoryHintOfAnErrorIsAddedToTheMessage(): void
    {
        $transport = $this->answering(FakeTransport::jsonResponse(
            '{"error":"invalid_request","error_description":"The request is missing a parameter.","hint":"Check the `code` parameter"}',
            400,
        ));

        try {
            $this->client($transport)->exchangeAuthorizationCode('c', 's', 's');
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertStringEndsWith('invalid_request: The request is missing a parameter. (Check the `code` parameter)', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{TransportResponse, non-empty-string}>
     */
    public static function answersWithoutAnRfcError(): iterable
    {
        yield 'HTML from a proxy' => [new TransportResponse(502, '<html lang="en"><body>Bad Gateway</body></html>', ['content-type' => 'text/html']), 'failed (HTTP 502).'];
        yield 'empty body' => [new TransportResponse(503, ''), 'failed (HTTP 503).'];
        yield 'JSON that is not an object' => [FakeTransport::jsonResponse('[1,2]', 500), 'failed (HTTP 500).'];
        yield 'only a message' => [FakeTransport::jsonResponse('{"message":"Maintenance"}', 503), 'failed (HTTP 503): Maintenance'];
        yield 'a redirect' => [new TransportResponse(302, '', ['location' => 'https://elsewhere.example']), 'failed (HTTP 302).'];
    }

    /**
     * @param non-empty-string $ending
     */
    #[DataProvider('answersWithoutAnRfcError')]
    public function testAnAnswerWithoutAnRfcErrorStillFailsWithTheStatus(TransportResponse $response, string $ending): void
    {
        try {
            $this->client($this->answering($response))->refresh('token');
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertStringEndsWith($ending, $e->getMessage());
            self::assertSame($response->statusCode, $e->statusCode);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSuccessBodies(): iterable
    {
        yield 'not JSON' => ['<html lang="en">ok</html>'];
        yield 'empty' => [''];
        yield 'a list' => ['[{"access_token":"x"}]'];
        yield 'no access token' => ['{"token_type":"Bearer","expires_in":3600,"refresh_token":"r"}'];
        yield 'an empty access token' => ['{"access_token":"","expires_in":3600}'];
    }

    #[DataProvider('unusableSuccessBodies')]
    public function testASuccessAnswerThatIsNotATokenIsAParseError(string $body): void
    {
        try {
            $this->client($this->answering(FakeTransport::jsonResponse($body)))->clientCredentials();
            self::fail('Expected a FurgonetkaApiResponseParseException.');
        } catch (FurgonetkaApiResponseParseException $e) {
            self::assertStringNotContainsString($body === '' ? "\0" : $body, $e->getMessage(), 'The body must not leak into the message.');
            self::assertNull($e->getRawResponse());
        }
    }

    /**
     * @return iterable<string, array{string, int, bool}> body, expected lifetime in seconds, whether a warning is logged
     */
    public static function lifetimes(): iterable
    {
        yield 'as documented' => ['{"access_token":"a","expires_in":7200}', 7200, false];
        yield 'as a numeric string' => ['{"access_token":"a","expires_in":"7200"}', 7200, false];
        yield 'missing' => ['{"access_token":"a"}', OAuthClient::DEFAULT_LIFETIME, true];
        yield 'zero' => ['{"access_token":"a","expires_in":0}', OAuthClient::DEFAULT_LIFETIME, true];
        yield 'negative' => ['{"access_token":"a","expires_in":-5}', OAuthClient::DEFAULT_LIFETIME, true];
        yield 'not a number' => ['{"access_token":"a","expires_in":"soon"}', OAuthClient::DEFAULT_LIFETIME, true];
    }

    #[DataProvider('lifetimes')]
    public function testTheLifetimeComesFromExpiresInWithASafeFallback(string $body, int $seconds, bool $warns): void
    {
        $logger = new RecordingLogger();

        $token = $this->client($this->answering(FakeTransport::jsonResponse($body)), logger: $logger)->clientCredentials();

        self::assertSame(TokenFactory::now()->getTimestamp() + $seconds, $token->expiresAt->getTimestamp());
        self::assertSame($warns, $logger->has('warning', 'no usable expires_in'));
    }

    public function testATransportFailureIsRethrownAndLogged(): void
    {
        $failure = new FurgonetkaApiTransportException('Connection timed out');
        $logger = new RecordingLogger();

        try {
            $this->client(new FakeTransport([$failure]), logger: $logger)->refresh('token');
            self::fail('Expected the transport exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame($failure, $e);
        }

        self::assertTrue($logger->has('error', 'Connection timed out'));
    }

    // -- Logging and debug mode ------------------------------------------------

    public function testASuccessfulGrantIsLoggedWithoutTheTokens(): void
    {
        $logger = new RecordingLogger();

        $this->client($this->answering(TokenFactory::tokenResponse('access-secret-1', 'refresh-secret2')), logger: $logger)->passwordGrant('u', 'p');

        self::assertTrue($logger->has('info', 'OAuth token obtained'));
        self::assertStringNotContainsString('access-secret-1', $logger->dump());
        self::assertStringNotContainsString('refresh-secret2', $logger->dump());
        self::assertSame('password grant', $logger->records[0]['context']['grant']);
        self::assertTrue($logger->records[0]['context']['refreshable']);
    }

    public function testTheRawAnswerIsAttachedOnlyInDebugModeAndRedacted(): void
    {
        $body = '{"access_token":"access-secret-1","refresh_token":"refresh-secret2","expires_in":';

        foreach ([false, true] as $debug) {
            try {
                $this->client(
                    $this->answering(FakeTransport::jsonResponse($body)),
                    TokenFactory::config(debug: $debug),
                )->clientCredentials();
                self::fail('Expected a FurgonetkaApiResponseParseException.');
            } catch (FurgonetkaApiResponseParseException $e) {
                if (!$debug) {
                    self::assertNull($e->getRawResponse());
                    continue;
                }

                self::assertNotNull($e->getRawResponse());
                self::assertStringContainsString('[redacted]', $e->getRawResponse());
                self::assertStringNotContainsString('access-secret-1', $e->getDebugReport());
                self::assertStringNotContainsString('refresh-secret2', $e->getDebugReport());
            }
        }
    }

    public function testADebugReportOfAnErrorIsLoggedInDebugMode(): void
    {
        $quiet = new RecordingLogger();
        $debug = new RecordingLogger();
        $answer = TokenFactory::errorResponse('invalid_client', 'Client authentication failed', 401);

        foreach ([[$quiet, false], [$debug, true]] as [$logger, $mode]) {
            try {
                $this->client($this->answering($answer), TokenFactory::config(debug: $mode), $logger)->clientCredentials();
            } catch (FurgonetkaApiOAuthException) {
                // Expected; the log is what is being checked.
            }
        }

        self::assertStringNotContainsString('Stack trace', $quiet->records[0]['message']);
        self::assertStringContainsString('Stack trace', $debug->records[0]['message']);
        self::assertStringContainsString('"error":"invalid_client"', $debug->records[0]['message']);
    }

    // -- Compatibility ---------------------------------------------------------

    public function testItRecognisesTheTokensItIssues(): void
    {
        $client = $this->client(new FakeTransport());

        self::assertTrue($client->isCompatible(TokenFactory::token()));
        self::assertFalse($client->isCompatible(TokenFactory::token(sandbox: false)));
        self::assertFalse($client->isCompatible(TokenFactory::token(clientId: 'another-application')));
    }

    public function testTokensAreStampedWithTheEnvironmentAndApplicationOfTheClient(): void
    {
        $token = $this->client($this->answering(TokenFactory::tokenResponse()), TokenFactory::config(sandbox: false))->refresh('r');

        self::assertSame('production', $token->environment);
        self::assertTrue($this->client(new FakeTransport(), TokenFactory::config(sandbox: false))->isCompatible($token));
        self::assertFalse($this->client(new FakeTransport())->isCompatible($token));
    }
}
