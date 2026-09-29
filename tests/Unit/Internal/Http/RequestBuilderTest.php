<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Enum\Language;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\EndpointAuth;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RequestBuilder;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class RequestBuilderTest extends TestCase
{
    /** 15 characters, so that a leak into a stack trace (which cuts arguments to 15) would show whole. */
    private const TOKEN = 'bearer-secret-1';

    private function builder(?FurgonetkaApiConfig $config = null): RequestBuilder
    {
        return new RequestBuilder($config ?? TokenFactory::config());
    }

    /**
     * Whatever arguments an operation needs, in the simplest form.
     *
     * @return array{array<string, string|int>, array<string, mixed>|null, string|null}
     */
    private static function argumentsFor(Endpoint $endpoint): array
    {
        $path = [];
        foreach ($endpoint->pathParameters() as $name) {
            $path[$name] = 'value-of-' . $name;
        }

        return [$path, $endpoint->hasBody() ? ['key' => 'value'] : null, $endpoint->auth() === EndpointAuth::User ? self::TOKEN : null];
    }

    /** @return iterable<string, array{Endpoint}> */
    public static function endpoints(): iterable
    {
        foreach (Endpoint::cases() as $endpoint) {
            yield $endpoint->name => [$endpoint];
        }
    }

    // -- Every operation sends its registered version ---------------------------

    #[DataProvider('endpoints')]
    public function testEveryOperationSendsTheVersionFromTheRegistry(Endpoint $endpoint): void
    {
        [$path, $body, $token] = self::argumentsFor($endpoint);

        $request = $this->builder()->build($endpoint, $path, [], $body, $token);

        self::assertSame($endpoint->accept(), $request->header('accept'));
        self::assertSame($endpoint->contentType(), $request->header('content-type'));
        self::assertSame($endpoint->method(), $request->method);

        $version = $endpoint->version();

        if ($version !== null) {
            self::assertStringContainsString("application/vnd.furgonetka.v{$version}+json", (string) $request->header('accept'));

            if ($endpoint->hasBody()) {
                self::assertSame("application/vnd.furgonetka.v{$version}+json", $request->header('content-type'));
            }
        }
    }

    #[DataProvider('endpoints')]
    public function testEveryOperationSendsTheLanguageAndOnlyAuthenticatedOnesTheToken(Endpoint $endpoint): void
    {
        [$path, $body, $token] = self::argumentsFor($endpoint);

        $request = $this->builder()->build($endpoint, $path, [], $body, $token);

        self::assertSame('en_GB', $request->header('x-language'));
        self::assertSame(
            $endpoint->auth() === EndpointAuth::User ? 'Bearer ' . self::TOKEN : null,
            $request->header('authorization'),
        );
    }

    public function testTheVersionsThatMatterForShipmentsAreV2(): void
    {
        $request = $this->builder()->build(Endpoint::CreatePackage, body: ['a' => 1], accessToken: self::TOKEN);

        self::assertSame('application/vnd.furgonetka.v2+json', $request->header('accept'));
        self::assertSame('application/vnd.furgonetka.v2+json', $request->header('content-type'));
    }

    // -- URL --------------------------------------------------------------------

    public function testTheUrlIsTheHostOfTheEnvironmentPlusThePath(): void
    {
        $sandbox = $this->builder()->build(Endpoint::ListServices, accessToken: self::TOKEN);
        $production = $this->builder(TokenFactory::config(sandbox: false))->build(Endpoint::ListServices, accessToken: self::TOKEN);

        self::assertSame('https://api.sandbox.furgonetka.pl/account/services', $sandbox->url);
        self::assertSame('https://api.furgonetka.pl/account/services', $production->url);
    }

    public function testPathParametersAreFilledInAndPercentEncoded(): void
    {
        $request = $this->builder()->build(Endpoint::FetchLabel, ['package_id' => '90014858123'], accessToken: self::TOKEN);

        self::assertSame('https://api.sandbox.furgonetka.pl/packages/90014858123/label', $request->url);

        $odd = $this->builder()->build(Endpoint::FetchPackage, ['id' => 'a b/c?d#e'], accessToken: self::TOKEN);

        self::assertSame('https://api.sandbox.furgonetka.pl/packages/a%20b%2Fc%3Fd%23e', $odd->url);
    }

    public function testAnIntegerPathParameterIsAccepted(): void
    {
        $request = $this->builder()->build(Endpoint::RenameTrackedPackage, ['package_id' => 42], body: ['name' => 'x'], accessToken: self::TOKEN);

        self::assertStringEndsWith('/packages/edit-tracked-package/42', $request->url);
    }

    /**
     * @return iterable<string, array{Endpoint, array<string, string|int>, string}>
     */
    public static function badPathParameters(): iterable
    {
        yield 'missing' => [Endpoint::FetchPackage, [], 'needs the path parameter "id"'];
        yield 'empty' => [Endpoint::FetchPackage, ['id' => ''], 'needs the path parameter "id"'];
        yield 'blank' => [Endpoint::FetchPackage, ['id' => '  '], 'needs the path parameter "id"'];
        yield 'a typo in the name' => [Endpoint::FetchPackage, ['package_id' => '1'], 'has no path parameter "package_id"; it takes: id'];
        yield 'one too many' => [Endpoint::ListServices, ['id' => '1'], 'has no path parameter "id"; it takes: none'];
    }

    /**
     * @param array<string, string|int> $parameters
     */
    #[DataProvider('badPathParameters')]
    public function testBadPathParametersAreRejected(Endpoint $endpoint, array $parameters, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->builder()->build($endpoint, $parameters, accessToken: self::TOKEN);
    }

    // -- Query ------------------------------------------------------------------

    public function testTheQueryIsAppended(): void
    {
        $request = $this->builder()->build(Endpoint::ListPackages, query: ['limit' => 20, 'list_type' => 'sent'], accessToken: self::TOKEN);

        self::assertSame('https://api.sandbox.furgonetka.pl/packages?limit=20&list_type=sent', $request->url);
    }

    public function testNullsAndEmptyArraysAreLeftOutAndNoQuestionMarkRemains(): void
    {
        $request = $this->builder()->build(Endpoint::ListPackages, query: ['limit' => null, 'query' => null, 'filters' => []], accessToken: self::TOKEN);

        self::assertSame('https://api.sandbox.furgonetka.pl/packages', $request->url);
    }

    public function testBooleansAreSentAsTrueAndFalse(): void
    {
        $request = $this->builder()->build(Endpoint::FetchPackage, ['id' => '1'], ['original_services' => false, 'type' => 'sent'], accessToken: self::TOKEN);

        self::assertStringEndsWith('/packages/1?original_services=false&type=sent', $request->url);

        $yes = $this->builder()->build(Endpoint::FetchPackage, ['id' => '1'], ['original_services' => true], accessToken: self::TOKEN);

        self::assertStringEndsWith('?original_services=true', $yes->url);
    }

    public function testNestedFiltersAreWrittenWithBracketsAndEncodedPerRfc3986(): void
    {
        $request = $this->builder()->build(
            Endpoint::ListPackages,
            query: ['query' => 'Jan Kowalski', 'filters' => ['service' => ['dpd', 'inpost'], 'state' => 'sent']],
            accessToken: self::TOKEN,
        );

        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $parsed);

        self::assertSame(['query' => 'Jan Kowalski', 'filters' => ['service' => ['dpd', 'inpost'], 'state' => 'sent']], $parsed);
        self::assertStringContainsString('query=Jan%20Kowalski', $request->url, 'A space is %20, not +.');
        self::assertStringContainsString('filters%5Bservice%5D%5B0%5D=dpd', $request->url);
    }

    public function testAnObjectInTheQueryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query parameter "limit" must be a scalar or an array, stdClass given');

        // Through reflection, because the point is that a wrong type is refused at runtime and
        // static analysis would (rightly) refuse to compile the call.
        (new \ReflectionMethod(RequestBuilder::class, 'build'))->invoke(
            $this->builder(),
            Endpoint::ListPackages,
            [],
            ['limit' => new \stdClass()],
            null,
            self::TOKEN,
        );
    }

    // -- Body -------------------------------------------------------------------

    public function testTheBodyIsJsonWithSlashesAndUnicodeUntouched(): void
    {
        $request = $this->builder()->build(
            Endpoint::CreatePackage,
            body: ['receiver' => ['name' => 'Łukasz Żółć', 'street' => 'ul. 1/2'], 'weight' => 5.5, 'cod' => null],
            accessToken: self::TOKEN,
        );

        self::assertSame('{"receiver":{"name":"Łukasz Żółć","street":"ul. 1/2"},"weight":5.5,"cod":null}', $request->body);
    }

    public function testAnEmptyBodyIsAnObjectNotAList(): void
    {
        $request = $this->builder()->build(Endpoint::ValidatePackage, body: [], accessToken: self::TOKEN);

        self::assertSame('{}', $request->body);
    }

    public function testAnOperationWithoutABodyGetsNone(): void
    {
        $request = $this->builder()->build(Endpoint::ListServices, accessToken: self::TOKEN);

        self::assertNull($request->body);
        self::assertNull($request->header('content-type'));
    }

    public function testABodyIsRequiredWhereTheOperationTakesOne(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('POST /packages takes a JSON body, none was given');

        $this->builder()->build(Endpoint::CreatePackage, accessToken: self::TOKEN);
    }

    public function testABodyIsRefusedWhereTheOperationTakesNone(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GET /account/services takes no body');

        $this->builder()->build(Endpoint::ListServices, body: ['a' => 1], accessToken: self::TOKEN);
    }

    public function testABodyThatIsNotValidUtf8IsRejectedWithoutQuotingIt(): void
    {
        // 15 characters of personal data, so that quoting it in a trace would show whole.
        $name = "Jan\xB1Kowalski-15";

        try {
            $this->builder()->build(Endpoint::CreatePackage, body: ['receiver' => ['name' => $name]], accessToken: self::TOKEN);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot be encoded as JSON', $e->getMessage());
            self::assertStringNotContainsString('Kowalski', $e->getMessage());
            self::assertNull($e->getPrevious(), 'A JsonException would carry the whole body in its trace.');
        }
    }

    // -- Authentication and headers ---------------------------------------------

    public function testAnAuthenticatedOperationNeedsAToken(): void
    {
        foreach ([null, ''] as $token) {
            try {
                $this->builder()->build(Endpoint::ListServices, accessToken: $token);
                self::fail('Expected a LogicException.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('GET /account/services needs an access token', $e->getMessage());
            }
        }
    }

    public function testAPublicOperationNeverGetsTheToken(): void
    {
        $dictionary = $this->builder()->build(Endpoint::FetchDictionary, accessToken: self::TOKEN);
        $points = $this->builder()->build(Endpoint::FindPoints, body: ['a' => 1], accessToken: self::TOKEN);

        self::assertNull($dictionary->header('authorization'));
        self::assertNull($points->header('authorization'));
        self::assertNull($dictionary->header('x-company-id'));
    }

    public function testPolishIsSentWhenTheConfigAsksForIt(): void
    {
        $config = new FurgonetkaApiConfig(TokenFactory::config()->credentials, true, Language::Polish);

        $request = $this->builder($config)->build(Endpoint::ListServices, accessToken: self::TOKEN);

        self::assertSame('pl_PL', $request->header('x-language'));
    }

    public function testTheCompanyIsSelectedOnAuthenticatedCallsOnly(): void
    {
        $config = TokenFactory::config()->withCompanyId(508899);

        $authenticated = $this->builder($config)->build(Endpoint::ListServices, accessToken: self::TOKEN);
        $public = $this->builder($config)->build(Endpoint::FetchDictionary);

        self::assertSame('508899', $authenticated->header('x-company-id'));
        self::assertNull($public->header('x-company-id'));
        self::assertNull($this->builder()->build(Endpoint::ListServices, accessToken: self::TOKEN)->header('x-company-id'));
    }

    public function testTimeoutsComeFromTheConfig(): void
    {
        $config = new FurgonetkaApiConfig(TokenFactory::config()->credentials, true, timeout: 7, connectTimeout: 3);

        $request = $this->builder($config)->build(Endpoint::ListServices, accessToken: self::TOKEN);

        self::assertSame(7, $request->timeout);
        self::assertSame(3, $request->connectTimeout);
    }

    public function testTheTokenDoesNotAppearInTheTraceOfAnError(): void
    {
        try {
            // The token is passed, then the call fails for another reason.
            $this->builder()->build(Endpoint::CreatePackage, accessToken: self::TOKEN);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getTraceAsString() . print_r($e->getTrace(), true));
        }
    }

    public function testTheTokenDoesNotAppearInADumpOfTheRequest(): void
    {
        $request = $this->builder()->build(Endpoint::ListServices, accessToken: self::TOKEN);

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::TOKEN, $dump . print_r($request, true));
        self::assertStringContainsString('Bearer [redacted]', $dump);
    }
}
