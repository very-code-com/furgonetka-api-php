<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthCredentials;
use VeryCodeCom\FurgonetkaApi\Enum\Language;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;

final class FurgonetkaApiConfigTest extends TestCase
{
    /**
     * Exactly 15 characters: getTraceAsString() cuts string arguments to 15, so a leaked secret
     * of this length would show up whole and the trace assertions below can actually fail.
     */
    private const SECRET = 'cfg-secret-9f3a';

    /** @var list<string> */
    private const ENV_KEYS = [
        'FURGONETKA_CLIENT_ID',
        'FURGONETKA_CLIENT_SECRET',
        'FURGONETKA_ENV',
        'FURGONETKA_REDIRECT_URI',
        'FURGONETKA_LANGUAGE',
        'FURGONETKA_COMPANY_ID',
        'FURGONETKA_TIMEOUT',
        'FURGONETKA_CONNECT_TIMEOUT',
        'FURGONETKA_DEBUG',
    ];

    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    // -- Named constructors ------------------------------------------------------

    public function testNamedConstructorsSelectTheRightHost(): void
    {
        self::assertSame('https://api.sandbox.furgonetka.pl', FurgonetkaApiConfig::sandbox('id', 's')->getBaseUrl());
        self::assertSame('https://api.furgonetka.pl', FurgonetkaApiConfig::production('id', 's')->getBaseUrl());
        self::assertSame('sandbox', FurgonetkaApiConfig::sandbox('id', 's')->getEnvironment());
        self::assertSame('production', FurgonetkaApiConfig::production('id', 's')->getEnvironment());
    }

    public function testNamedConstructorsCarryTheKeysAndTheRedirectUri(): void
    {
        $config = FurgonetkaApiConfig::sandbox('the-id', 'the-secret', 'http://localhost:8080/oauth/callback');

        self::assertTrue($config->hasCredentials());
        self::assertSame('the-id', $config->credentials?->clientId);
        self::assertSame('the-secret', $config->credentials->clientSecret);
        self::assertSame('http://localhost:8080/oauth/callback', $config->credentials->redirectUri);
    }

    public function testAConfigWithoutKeysIsValidForPublicEndpoints(): void
    {
        $config = FurgonetkaApiConfig::production();

        self::assertFalse($config->hasCredentials());
        self::assertNull($config->credentials);
    }

    public function testHalfAPairOfKeysIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clientId and clientSecret must be given together');

        FurgonetkaApiConfig::production('only-an-id');
    }

    public function testARedirectUriWithoutKeysIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a clientId and a clientSecret');

        FurgonetkaApiConfig::production(redirectUri: 'http://localhost/cb');
    }

    public function testUrlJoinsHostAndPathWithoutAVersionPrefix(): void
    {
        $config = FurgonetkaApiConfig::sandbox('id', 's');

        // The API version is chosen per endpoint through the media type, never through the path.
        self::assertSame('https://api.sandbox.furgonetka.pl/packages', $config->url('packages'));
        self::assertSame('https://api.sandbox.furgonetka.pl/packages', $config->url('/packages'));
        self::assertSame('https://api.sandbox.furgonetka.pl/oauth/token', $config->url('oauth/token'));
    }

    public function testDefaults(): void
    {
        $config = FurgonetkaApiConfig::production('id', 's');

        self::assertSame(Language::English, $config->language);
        self::assertNull($config->companyId);
        self::assertSame(30, $config->timeout);
        self::assertSame(10, $config->connectTimeout);
        self::assertFalse($config->debug);
        self::assertTrue($config->verifySsl());
    }

    public function testConstructorRejectsNonsenseNumbers(): void
    {
        foreach ([
            fn () => new FurgonetkaApiConfig(companyId: 0),
            fn () => new FurgonetkaApiConfig(timeout: 0),
            fn () => new FurgonetkaApiConfig(connectTimeout: -1),
        ] as $build) {
            try {
                $build();
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('FurgonetkaApiConfig', $e->getMessage());
            }
        }
    }

    public function testWithCompanyIdReturnsAnotherConfigAndKeepsTheRest(): void
    {
        $config = new FurgonetkaApiConfig(
            new OAuthCredentials('id', 's'),
            sandbox: true,
            language: Language::Polish,
            timeout: 45,
            connectTimeout: 5,
            debug: true,
        );

        $other = $config->withCompanyId(77);

        self::assertNotSame($config, $other);
        self::assertNull($config->companyId, 'The original is untouched.');
        self::assertSame(77, $other->companyId);
        self::assertSame($config->credentials, $other->credentials);
        self::assertTrue($other->sandbox);
        self::assertSame(Language::Polish, $other->language);
        self::assertSame(45, $other->timeout);
        self::assertSame(5, $other->connectTimeout);
        self::assertTrue($other->debug);
        self::assertSame($config, $config->withCompanyId(null), 'No change, no copy.');
        self::assertNull($other->withCompanyId(null)->companyId);
    }

    // -- fromEnv -----------------------------------------------------------------

    public function testFromEnvRequiresTheClientId(): void
    {
        $this->setEnv(['FURGONETKA_CLIENT_SECRET' => 's']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_CLIENT_ID is required');

        FurgonetkaApiConfig::fromEnv();
    }

    public function testFromEnvRequiresTheClientSecret(): void
    {
        $this->setEnv(['FURGONETKA_CLIENT_ID' => 'id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_CLIENT_SECRET is required');

        FurgonetkaApiConfig::fromEnv();
    }

    public function testFromEnvDefaultsToProductionAndEnglish(): void
    {
        $this->setEnv(['FURGONETKA_CLIENT_ID' => 'id', 'FURGONETKA_CLIENT_SECRET' => 's']);

        $config = FurgonetkaApiConfig::fromEnv();

        self::assertSame('production', $config->getEnvironment());
        self::assertSame(Language::English, $config->language);
        self::assertSame(30, $config->timeout);
        self::assertSame(10, $config->connectTimeout);
        self::assertFalse($config->debug);
    }

    public function testFromEnvReadsEveryVariable(): void
    {
        $this->setEnv([
            'FURGONETKA_CLIENT_ID' => ' the-id ',
            'FURGONETKA_CLIENT_SECRET' => self::SECRET,
            'FURGONETKA_ENV' => 'Sandbox',
            'FURGONETKA_REDIRECT_URI' => 'http://localhost:8080/oauth/callback',
            'FURGONETKA_LANGUAGE' => 'pl_PL',
            'FURGONETKA_COMPANY_ID' => '1234',
            'FURGONETKA_TIMEOUT' => '60',
            'FURGONETKA_CONNECT_TIMEOUT' => '5',
            'FURGONETKA_DEBUG' => 'yes',
        ]);

        $config = FurgonetkaApiConfig::fromEnv();

        self::assertSame('the-id', $config->credentials?->clientId);
        self::assertSame(self::SECRET, $config->credentials->clientSecret);
        self::assertSame('http://localhost:8080/oauth/callback', $config->credentials->redirectUri);
        self::assertTrue($config->sandbox);
        self::assertSame(Language::Polish, $config->language);
        self::assertSame(1234, $config->companyId);
        self::assertSame(60, $config->timeout);
        self::assertSame(5, $config->connectTimeout);
        self::assertTrue($config->debug);
    }

    public function testFromEnvFallsBackToEnvAndServerSuperglobals(): void
    {
        // Depending on variables_order, getenv() may not see what the web server passed in.
        $_ENV['FURGONETKA_CLIENT_ID'] = 'from-env-array';
        $_SERVER['FURGONETKA_CLIENT_SECRET'] = 'from-server-array';

        $config = FurgonetkaApiConfig::fromEnv();

        self::assertSame('from-env-array', $config->credentials?->clientId);
        self::assertSame('from-server-array', $config->credentials->clientSecret);
    }

    public function testFromEnvIgnoresNonStringSuperglobalEntries(): void
    {
        $_SERVER['FURGONETKA_CLIENT_ID'] = ['not', 'a', 'string'];
        $this->setEnv(['FURGONETKA_CLIENT_SECRET' => 's']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_CLIENT_ID is required');

        FurgonetkaApiConfig::fromEnv();
    }

    public function testAMisspeltEnvironmentIsRejectedRatherThanTreatedAsProduction(): void
    {
        // Production means real, billed shipments; a typo must not get there quietly.
        $this->setEnv(['FURGONETKA_CLIENT_ID' => 'id', 'FURGONETKA_CLIENT_SECRET' => 's', 'FURGONETKA_ENV' => 'sandbx']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_ENV must be "sandbox" or "production", got "sandbx"');

        FurgonetkaApiConfig::fromEnv();
    }

    public function testAnUnsupportedLanguageIsRejected(): void
    {
        // The API treats an unknown X-Language exactly like a missing one, so it would fail silently.
        $this->setEnv(['FURGONETKA_CLIENT_ID' => 'id', 'FURGONETKA_CLIENT_SECRET' => 's', 'FURGONETKA_LANGUAGE' => 'de_DE']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_LANGUAGE must be "en_GB" or "pl_PL"');

        FurgonetkaApiConfig::fromEnv();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'zero timeout' => ['FURGONETKA_TIMEOUT', '0'];
        yield 'negative timeout' => ['FURGONETKA_TIMEOUT', '-5'];
        yield 'word as timeout' => ['FURGONETKA_TIMEOUT', 'soon'];
        yield 'decimal connect timeout' => ['FURGONETKA_CONNECT_TIMEOUT', '2.5'];
        yield 'zero company' => ['FURGONETKA_COMPANY_ID', '0'];
        yield 'named company' => ['FURGONETKA_COMPANY_ID', 'acme'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNumbers')]
    public function testInvalidNumbersAreRejected(string $variable, string $value): void
    {
        $this->setEnv(['FURGONETKA_CLIENT_ID' => 'id', 'FURGONETKA_CLIENT_SECRET' => 's', $variable => $value]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("{$variable} must be a positive integer");

        FurgonetkaApiConfig::fromEnv();
    }

    public function testFlagsAcceptTheUsualSpellingsAndRejectTheRest(): void
    {
        foreach (['1', 'true', 'YES', ' on '] as $truthy) {
            $this->setEnv(['FURGONETKA_CLIENT_ID' => 'id', 'FURGONETKA_CLIENT_SECRET' => 's', 'FURGONETKA_DEBUG' => $truthy]);
            self::assertTrue(FurgonetkaApiConfig::fromEnv()->debug, "'{$truthy}' should be on");
        }

        foreach (['0', 'false', 'No', 'off'] as $falsy) {
            $this->setEnv(['FURGONETKA_DEBUG' => $falsy]);
            self::assertFalse(FurgonetkaApiConfig::fromEnv()->debug, "'{$falsy}' should be off");
        }

        $this->setEnv(['FURGONETKA_DEBUG' => 'maybe']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FURGONETKA_DEBUG must be a boolean');

        FurgonetkaApiConfig::fromEnv();
    }

    // -- fromArray ---------------------------------------------------------------

    public function testFromArrayRequiresTheKeys(): void
    {
        try {
            FurgonetkaApiConfig::fromArray(['client_secret' => 's']);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('fromArray(): client_id is required', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fromArray(): client_secret is required');

        FurgonetkaApiConfig::fromArray(['client_id' => 'id']);
    }

    public function testFromArrayAcceptsTypedValuesAsFrameworkConfigsHoldThem(): void
    {
        $config = FurgonetkaApiConfig::fromArray([
            'client_id' => 'the-id',
            'client_secret' => 'the-secret',
            'env' => 'sandbox',
            'redirect_uri' => 'https://shop.example/furgonetka/callback',
            'language' => Language::Polish,
            'company_id' => 9,
            'timeout' => 45,
            'connect_timeout' => '3',
            'debug' => true,
            'some_other_framework_key' => 'ignored',
        ]);

        self::assertTrue($config->sandbox);
        self::assertSame('https://shop.example/furgonetka/callback', $config->credentials?->redirectUri);
        self::assertSame(Language::Polish, $config->language);
        self::assertSame(9, $config->companyId);
        self::assertSame(45, $config->timeout);
        self::assertSame(3, $config->connectTimeout);
        self::assertTrue($config->debug);
    }

    public function testFromArrayAcceptsALanguageGivenAsText(): void
    {
        $config = FurgonetkaApiConfig::fromArray(['client_id' => 'id', 'client_secret' => 's', 'language' => 'pl_PL']);

        self::assertSame(Language::Polish, $config->language);
    }

    public function testFromArrayAcceptsANumericClientId(): void
    {
        $config = FurgonetkaApiConfig::fromArray(['client_id' => 344, 'client_secret' => 's']);

        self::assertSame('344', $config->credentials?->clientId);
    }

    public function testFromArrayRejectsValuesOfTheWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('client_secret must be a string');

        FurgonetkaApiConfig::fromArray(['client_id' => 'id', 'client_secret' => ['nope']]);
    }

    public function testFromArrayFlagsAcceptBooleansAndZeroOne(): void
    {
        $base = ['client_id' => 'id', 'client_secret' => 's'];

        self::assertTrue(FurgonetkaApiConfig::fromArray($base + ['debug' => 1])->debug);
        self::assertFalse(FurgonetkaApiConfig::fromArray($base + ['debug' => 0])->debug);
        self::assertFalse(FurgonetkaApiConfig::fromArray($base + ['debug' => false])->debug);
        self::assertFalse(FurgonetkaApiConfig::fromArray($base)->debug);

        $this->expectException(\InvalidArgumentException::class);

        FurgonetkaApiConfig::fromArray($base + ['debug' => 2]);
    }

    // -- Secrets -----------------------------------------------------------------

    public function testTheSecretDoesNotAppearInDumps(): void
    {
        $config = FurgonetkaApiConfig::sandbox('the-id', self::SECRET);

        self::assertStringNotContainsString(self::SECRET, print_r($config, true));
        self::assertStringNotContainsString(self::SECRET, var_export($config->credentials?->__debugInfo(), true));

        ob_start();
        var_dump($config);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringContainsString('the-id', $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    public function testTheSecretDoesNotAppearInAStackTrace(): void
    {
        // A failure deep inside the config must not print the secret in getTraceAsString().
        try {
            FurgonetkaApiConfig::sandbox('the-id', self::SECRET, redirectUri: '   ');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
        }

        try {
            FurgonetkaApiConfig::fromArray(['client_id' => 'the-id', 'client_secret' => self::SECRET, 'timeout' => 'soon']);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getTraceAsString());
        }
    }
}
