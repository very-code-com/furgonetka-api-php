<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthCredentials;
use VeryCodeCom\FurgonetkaApi\Auth\PasswordReauthorizer;
use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\Store\InMemoryTokenStore;
use VeryCodeCom\FurgonetkaApi\Auth\TokenManager;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\RecordingLogger;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

/**
 * The secrets of a whole OAuth session, run through every place a secret could leak to: exception
 * messages, debug reports, stack traces, the raw response attached in debug mode, the PSR-3 log,
 * and `var_dump()`/`print_r()` of every object involved.
 *
 * Every secret is exactly 15 characters long on purpose. A stack trace cuts string arguments to
 * 15 characters, so a shorter secret would be cut and could never be seen leaking, and a test
 * built on it would pass whether or not `#[\SensitiveParameter]` is there. Remove the attribute
 * from any of the parameters that carry a secret and this test goes red.
 */
final class AuthSecretsTest extends TestCase
{
    private const CLIENT_SECRET = 'client-secret-1';
    private const PASSWORD = 'account-pass-15';
    private const CODE = 'auth-code-sec-1';
    private const TWO_FACTOR = '2fa-code-secret';
    private const REFRESH = 'refresh-secret2';
    private const ACCESS = 'access-secret-1';

    /** @return array<string, string> */
    private static function secrets(): array
    {
        return [
            'client secret' => self::CLIENT_SECRET,
            'client secret in the Basic header' => base64_encode(TokenFactory::CLIENT_ID . ':' . self::CLIENT_SECRET),
            'password' => self::PASSWORD,
            'authorization code' => self::CODE,
            'two-factor code' => self::TWO_FACTOR,
            'refresh token' => self::REFRESH,
            'access token' => self::ACCESS,
        ];
    }

    public function testTheSecretsAreLongEnoughToShowUpInAStackTrace(): void
    {
        foreach ([self::CLIENT_SECRET, self::PASSWORD, self::CODE, self::TWO_FACTOR, self::REFRESH, self::ACCESS] as $secret) {
            self::assertSame(15, strlen($secret));
        }
    }

    /**
     * @return list<string> What the exception and everything it wraps would show a developer.
     */
    private function observe(\Throwable $exception): array
    {
        $seen = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $seen[] = $current->getMessage();
            $seen[] = $current->getTraceAsString();
            // getTrace() keeps whole arguments, arrays included, which is what error trackers read.
            $seen[] = print_r($current->getTrace(), true);
            $seen[] = (string) $current;

            if ($current instanceof FurgonetkaApiException) {
                $seen[] = $current->getDebugReport();
                $seen[] = (string) $current->getRawResponse();
            }
        }

        return $seen;
    }

    private function capture(callable $action): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected the action to throw.');
    }

    private function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean() . print_r($value, true);
    }

    public function testNoSecretOfAWholeSessionLeaksAnywhere(): void
    {
        $config = new FurgonetkaApiConfig(
            credentials: new OAuthCredentials(TokenFactory::CLIENT_ID, self::CLIENT_SECRET, TokenFactory::REDIRECT_URI),
            sandbox: true,
            debug: true,
        );
        $logger = new RecordingLogger();
        $transport = new FakeTransport();
        $oauth = new OAuthClient($config, $transport, $logger, static fn (): \DateTimeImmutable => TokenFactory::now());

        $bodyWithTokens = '{"token_type":"Bearer","access_token":"' . self::ACCESS . '","refresh_token":"' . self::REFRESH . '","expires_in":';
        $transport
            ->push(TokenFactory::errorResponse('invalid_grant', 'The user credentials were incorrect.'))         // password, wrong
            ->push(TokenFactory::tokenResponse(self::ACCESS, self::REFRESH))                                     // password, right
            ->push(TokenFactory::errorResponse('invalid_grant', 'The authorization code is invalid.', 401))     // code exchange
            ->push(TokenFactory::errorResponse('invalid_grant', 'The two-factor code is wrong.', 401))          // 2FA
            ->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400))          // refresh
            ->push(FakeTransport::jsonResponse($bodyWithTokens))                                                 // malformed answer holding tokens
            ->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400))          // manager, no reauthorizer
            ->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400))          // manager, reauthorizer: refresh
            ->push(FakeTransport::jsonResponse(TokenFactory::fixture('error-2fa-required'), 401))               // manager, reauthorizer: login, 2FA account
            ->push(TokenFactory::errorResponse('invalid_grant', 'The refresh token is invalid.', 400))          // manager, reauthorizer: refresh again
            ->push(TokenFactory::errorResponse('invalid_grant', 'The user credentials were incorrect.'))        // manager, reauthorizer: login, wrong password
            ->push(FakeTransport::jsonResponse('{"refresh_token":"' . self::REFRESH . '","expires_in":3600}'));   // an answer with a refresh token but no access token

        $exceptions = [
            $this->capture(fn () => $oauth->passwordGrant('shop@example.com', self::PASSWORD)),
        ];
        $token = $oauth->passwordGrant('shop@example.com', self::PASSWORD);
        $exceptions[] = $this->capture(fn () => $oauth->exchangeAuthorizationCode(self::CODE, 'state-a', 'state-a'));
        $exceptions[] = $this->capture(fn () => $oauth->exchangeAuthorizationCode(self::CODE, 'forged', 'state-a'));
        $exceptions[] = $this->capture(fn () => $oauth->completeTwoFactor('0d29d8ab-54da-49b6-b05f-d92b2a15ed0e', 'shop@example.com', self::TWO_FACTOR));
        $exceptions[] = $this->capture(fn () => $oauth->refresh(self::REFRESH));
        $exceptions[] = $this->capture(fn () => $oauth->clientCredentials());

        $store = new InMemoryTokenStore(TokenFactory::token(self::ACCESS, self::REFRESH, expiresIn: 10));
        $clock = static fn (): \DateTimeImmutable => TokenFactory::now();
        $plain = new TokenManager($oauth, $store, null, 300, $logger, $clock);
        $exceptions[] = $this->capture(fn () => $plain->accessToken());

        $reauthorizer = new PasswordReauthorizer('shop@example.com', self::PASSWORD);
        $reauthorizing = new TokenManager($oauth, new InMemoryTokenStore(TokenFactory::token(self::ACCESS, self::REFRESH, expiresIn: 10)), $reauthorizer, 300, $logger, $clock);
        $exceptions[] = $this->capture(fn () => $reauthorizing->accessToken());
        $exceptions[] = $this->capture(fn () => $reauthorizing->accessToken());
        $exceptions[] = $this->capture(fn () => $oauth->clientCredentials());

        $directory = sys_get_temp_dir() . '/furgonetka-secrets-test-' . bin2hex(random_bytes(6));
        $file = new FileTokenStore($directory . '/token.json', $logger);
        $file->save($token);

        try {
            $observed = [];
            foreach ($exceptions as $exception) {
                array_push($observed, ...$this->observe($exception));
            }

            $objects = [$config, $oauth, $config->credentials, $token, $store, $plain, $reauthorizer, $reauthorizing, $file, $transport, new StaticTokenProvider(self::ACCESS)];
            $dumps = array_map(fn (mixed $object): string => $this->dump($object), $objects);
            $logged = $logger->dump();

            self::assertCount(10, $exceptions);
            self::assertSame(0, $transport->remaining(), 'Every scripted answer must have been used, or the flows are out of step.');

            foreach (self::secrets() as $name => $secret) {
                foreach ($observed as $text) {
                    self::assertStringNotContainsString($secret, $text, "The {$name} leaked into an exception.");
                }

                foreach ($dumps as $dump) {
                    self::assertStringNotContainsString($secret, $dump, "The {$name} leaked into a dump.");
                }

                self::assertStringNotContainsString($secret, $logged, "The {$name} leaked into the log.");
            }
        } finally {
            @unlink($directory . '/token.json');
            @unlink($directory . '/token.json.lock');
            @rmdir($directory);
        }
    }

    public function testTheDebugReportKeepsTheRawAnswerWithTheTokensMasked(): void
    {
        $config = new FurgonetkaApiConfig(
            credentials: new OAuthCredentials(TokenFactory::CLIENT_ID, self::CLIENT_SECRET),
            sandbox: true,
            debug: true,
        );
        $transport = new FakeTransport([FakeTransport::jsonResponse('{"access_token":"' . self::ACCESS . '","refresh_token":"' . self::REFRESH . '","expires_in":')]);
        $oauth = new OAuthClient($config, $transport);

        $exception = $this->capture(fn () => $oauth->clientCredentials());

        self::assertInstanceOf(FurgonetkaApiException::class, $exception);
        // The debug report is only worth having if it still shows what came back.
        self::assertStringContainsString('"access_token":"[redacted]"', (string) $exception->getRawResponse());
        self::assertStringContainsString('"refresh_token":"[redacted]"', (string) $exception->getRawResponse());
    }
}
