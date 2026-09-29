<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\PasswordReauthorizer;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiOAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTwoFactorRequiredException;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class PasswordReauthorizerTest extends TestCase
{
    /** 15 characters, so that a leak into a stack trace (which cuts arguments to 15) would show whole. */
    private const PASSWORD = 'reauth-secret-1';

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach ([PasswordReauthorizer::ENV_USERNAME, PasswordReauthorizer::ENV_PASSWORD] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private function oauth(FakeTransport $transport): OAuthClient
    {
        return new OAuthClient(TokenFactory::config(), $transport, null, static fn (): \DateTimeImmutable => TokenFactory::now());
    }

    public function testItLogsInWithThePasswordGrant(): void
    {
        $transport = new FakeTransport([TokenFactory::tokenResponse('access-new', 'refresh-new')]);

        $token = (new PasswordReauthorizer('shop@example.com', self::PASSWORD))->reauthorize($this->oauth($transport));

        self::assertSame('access-new', $token->accessToken);
        self::assertSame([
            'grant_type' => 'password',
            'scope' => 'api',
            'username' => 'shop@example.com',
            'password' => self::PASSWORD,
        ], $transport->lastForm());
    }

    public function testWrongCredentialsSurfaceAsTheOAuthErrorFromFurgonetka(): void
    {
        $transport = new FakeTransport([TokenFactory::errorResponse('invalid_grant', 'The user credentials were incorrect.')]);

        try {
            (new PasswordReauthorizer('shop@example.com', 'wrong'))->reauthorize($this->oauth($transport));
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertNotInstanceOf(FurgonetkaApiReauthorizationRequiredException::class, $e);
            self::assertSame('The user credentials were incorrect.', $e->errorDescription);
        }
    }

    public function testAnAccountWithTwoFactorCannotBeReauthorizedWithoutAPerson(): void
    {
        $transport = new FakeTransport([FakeTransport::jsonResponse(TokenFactory::fixture('error-2fa-required'), 401)]);

        try {
            (new PasswordReauthorizer('shop@example.com', self::PASSWORD))->reauthorize($this->oauth($transport));
            self::fail('Expected a FurgonetkaApiReauthorizationRequiredException.');
        } catch (FurgonetkaApiReauthorizationRequiredException $e) {
            self::assertStringContainsString('two-factor authentication', $e->getMessage());
            self::assertSame('2fa_required', $e->error);
            self::assertInstanceOf(FurgonetkaApiTwoFactorRequiredException::class, $e->getPrevious());
        }
    }

    public function testFromEnvReadsTheTwoVariables(): void
    {
        putenv(PasswordReauthorizer::ENV_USERNAME . '=env-user@example.com');
        putenv(PasswordReauthorizer::ENV_PASSWORD . '=' . self::PASSWORD);
        $transport = new FakeTransport([TokenFactory::tokenResponse()]);

        PasswordReauthorizer::fromEnv()->reauthorize($this->oauth($transport));

        self::assertSame('env-user@example.com', $transport->lastForm()['username']);
        self::assertSame(self::PASSWORD, $transport->lastForm()['password']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteEnvironments(): iterable
    {
        yield 'nothing set' => [[]];
        yield 'no password' => [[PasswordReauthorizer::ENV_USERNAME => 'user@example.com']];
        yield 'no username' => [[PasswordReauthorizer::ENV_PASSWORD => 'pw']];
        yield 'blank values' => [[PasswordReauthorizer::ENV_USERNAME => '  ', PasswordReauthorizer::ENV_PASSWORD => '  ']];
    }

    /**
     * @param array<string, string> $environment
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteEnvironments')]
    public function testFromEnvNeedsBothVariables(array $environment): void
    {
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_USERNAME and FURGONETKA_PASSWORD are required');

        PasswordReauthorizer::fromEnv();
    }

    public function testEmptyCredentialsAreRejected(): void
    {
        foreach ([['', 'pw'], ['  ', 'pw'], ['user', '']] as [$user, $password]) {
            try {
                new PasswordReauthorizer($user, $password);
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testThePasswordDoesNotAppearInDumps(): void
    {
        $reauthorizer = new PasswordReauthorizer('shop@example.com', self::PASSWORD);

        ob_start();
        var_dump($reauthorizer);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::PASSWORD, $dump);
        self::assertStringNotContainsString(self::PASSWORD, print_r($reauthorizer, true));
        self::assertStringContainsString('shop@example.com', $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    public function testThePasswordDoesNotAppearInTheTraceOfARejectedConstruction(): void
    {
        try {
            new PasswordReauthorizer(' ', self::PASSWORD);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::PASSWORD, $e->getTraceAsString() . print_r($e->getTrace(), true));
        }
    }

    public function testThePasswordDoesNotAppearInAStackTrace(): void
    {
        $transport = new FakeTransport([TokenFactory::errorResponse('invalid_grant', 'The user credentials were incorrect.')]);

        try {
            (new PasswordReauthorizer('shop@example.com', self::PASSWORD))->reauthorize($this->oauth($transport));
            self::fail('Expected a FurgonetkaApiOAuthException.');
        } catch (FurgonetkaApiOAuthException $e) {
            self::assertStringNotContainsString(self::PASSWORD, $e->getTraceAsString());
        }
    }
}
