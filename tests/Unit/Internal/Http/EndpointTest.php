<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\EndpointAuth;

/**
 * The registry, operation by operation.
 *
 * {@see self::EXPECTED} is written out a second time on purpose: it is the statement of intent
 * ("the shipment operations are v2, everything else v1, the company list is unversioned"), while
 * {@see Endpoint} is the implementation. A change to one without the other fails here. Whether
 * the registry still matches Furgonetka's published specification is a separate question, asked
 * by {@see \VeryCodeCom\FurgonetkaApi\Tests\Unit\Tools\SpecDriftTest} against the snapshot of
 * 2026-09-28 and by `tools/spec-drift.php` against the live one.
 */
final class EndpointTest extends TestCase
{
    /** Version, authentication, takes a body, answers with a file. */
    private const EXPECTED = [
        'ListServices' => [1, EndpointAuth::User, false, false],
        'ListCompanies' => [null, EndpointAuth::User, false, false],
        'FetchAllowedCountries' => [1, EndpointAuth::None, false, false],
        'FetchDictionary' => [1, EndpointAuth::None, false, false],
        'ListRegulations' => [1, EndpointAuth::User, false, false],
        'AcceptRegulations' => [1, EndpointAuth::User, true, false],
        'RegulationsForPackages' => [1, EndpointAuth::User, false, false],
        'ValidatePackage' => [2, EndpointAuth::User, true, false],
        'CalculatePrices' => [2, EndpointAuth::User, true, false],
        'CreatePackage' => [2, EndpointAuth::User, true, false],
        'UpdatePackage' => [2, EndpointAuth::User, true, false],
        'FetchPackage' => [2, EndpointAuth::User, false, false],
        'ListPackages' => [2, EndpointAuth::User, false, false],
        'DeletePackage' => [1, EndpointAuth::User, false, false],
        'DeletePackages' => [1, EndpointAuth::User, true, false],
        'SubmitOrder' => [1, EndpointAuth::User, true, false],
        'FetchOrderCommand' => [1, EndpointAuth::User, false, false],
        'AbortOrderCommand' => [1, EndpointAuth::User, false, false],
        'SendDocumentsEmail' => [1, EndpointAuth::User, true, false],
        'FetchLabel' => [1, EndpointAuth::User, false, true],
        'SubmitDocuments' => [1, EndpointAuth::User, true, false],
        'FetchDocumentsCommand' => [1, EndpointAuth::User, false, false],
        'AbortDocumentsCommand' => [1, EndpointAuth::User, false, false],
        'FetchProtocol' => [1, EndpointAuth::User, true, true],
        'FetchPickupProposals' => [1, EndpointAuth::User, true, false],
        'SubmitPickup' => [1, EndpointAuth::User, true, false],
        'FetchPickupCommand' => [1, EndpointAuth::User, false, false],
        'AbortPickupCommand' => [1, EndpointAuth::User, false, false],
        'SubmitCancel' => [1, EndpointAuth::User, true, false],
        'FetchCancelCommand' => [1, EndpointAuth::User, false, false],
        'AbortCancelCommand' => [1, EndpointAuth::User, false, false],
        'MarkAsCancelled' => [1, EndpointAuth::User, true, false],
        'AbortGenericCommand' => [1, EndpointAuth::User, false, false],
        'FetchTracking' => [2, EndpointAuth::User, false, false],
        'AddToTracking' => [1, EndpointAuth::User, true, false],
        'RenameTrackedPackage' => [1, EndpointAuth::User, true, false],
        'SimulateTracking' => [1, EndpointAuth::User, true, false],
        'FindPoints' => [1, EndpointAuth::None, true, false],
        'FetchWebhookSettings' => [1, EndpointAuth::User, false, false],
        'ListWebhookLogs' => [1, EndpointAuth::User, false, false],
        'ListAddressBook' => [1, EndpointAuth::User, false, false],
        'CreateAddressBookEntry' => [1, EndpointAuth::User, true, false],
        'DeleteAddressBookEntries' => [1, EndpointAuth::User, true, false],
        'FetchAddressBookEntry' => [1, EndpointAuth::User, false, false],
        'ReplaceAddressBookEntry' => [1, EndpointAuth::User, true, false],
        'ValidateAddressBookEntry' => [1, EndpointAuth::User, true, false],
        'SubmitCsvExport' => [1, EndpointAuth::User, true, false],
        'FetchCsvExport' => [1, EndpointAuth::User, false, false],
        'AbortCsvExport' => [1, EndpointAuth::User, false, false],
        'SubmitRevalidate' => [1, EndpointAuth::User, true, false],
        'FetchRevalidate' => [1, EndpointAuth::User, false, false],
        'AbortRevalidate' => [1, EndpointAuth::User, false, false],
        'SubmitAddToTracking' => [1, EndpointAuth::User, true, false],
        'FetchAddToTracking' => [1, EndpointAuth::User, false, false],
        'AbortAddToTracking' => [1, EndpointAuth::User, false, false],
        'SubmitHsCodes' => [1, EndpointAuth::User, true, false],
        'FetchHsCodes' => [1, EndpointAuth::User, false, false],
        'SaveNotificationSettings' => [1, EndpointAuth::User, true, false],
        'FetchGeneralNotifications' => [1, EndpointAuth::User, false, false],
        'FetchShippingNotifications' => [1, EndpointAuth::User, false, false],
        'SubmitWebhookCheck' => [1, EndpointAuth::User, true, false],
        'FetchWebhookCheck' => [1, EndpointAuth::User, false, false],
        'SubmitWebhookResend' => [1, EndpointAuth::User, true, false],
        'FetchWebhookResend' => [1, EndpointAuth::User, false, false],
        'FetchBalance' => [1, EndpointAuth::User, false, false],
        'ListServiceAgreements' => [1, EndpointAuth::User, false, false],
        'ListPlannedPricelistChanges' => [1, EndpointAuth::User, false, false],
        'CalculateReturnPrice' => [1, EndpointAuth::User, true, false],
        'ReturnPackage' => [1, EndpointAuth::User, true, false],
        'CalculateReaddressingPrice' => [1, EndpointAuth::User, true, false],
        'ReaddressPackage' => [1, EndpointAuth::User, true, false],
        'FetchReaddressing' => [1, EndpointAuth::User, false, false],
        'CancelReaddressing' => [1, EndpointAuth::User, false, false],
        'CreateSendToMeLink' => [1, EndpointAuth::None, true, false],
        'SubmitCreatePackage' => [2, EndpointAuth::User, true, false],
        'FetchCreatePackageCommand' => [1, EndpointAuth::User, false, false],
        'AbortCreatePackageCommand' => [1, EndpointAuth::User, false, false],
        'UploadFile' => [null, EndpointAuth::User, false, false],
        'FetchFileCommands' => [null, EndpointAuth::User, false, false],
    ];

    /** @return iterable<string, array{Endpoint}> */
    public static function endpoints(): iterable
    {
        foreach (Endpoint::cases() as $endpoint) {
            yield $endpoint->name => [$endpoint];
        }
    }

    public function testEveryOperationHasAnExpectation(): void
    {
        self::assertSame(
            array_map(static fn (Endpoint $e): string => $e->name, Endpoint::cases()),
            array_keys(self::EXPECTED),
            'A case was added, removed or reordered without updating EXPECTED.',
        );
    }

    #[DataProvider('endpoints')]
    public function testEachOperationIsRegisteredAsIntended(Endpoint $endpoint): void
    {
        [$version, $auth, $hasBody, $returnsFile] = self::EXPECTED[$endpoint->name];

        self::assertSame($version, $endpoint->version(), "{$endpoint->value} version");
        self::assertSame($auth, $endpoint->auth(), "{$endpoint->value} authentication");
        self::assertSame($hasBody, $endpoint->hasBody(), "{$endpoint->value} body");
        self::assertSame($returnsFile, $endpoint->returnsFile(), "{$endpoint->value} file answer");
    }

    #[DataProvider('endpoints')]
    public function testTheBackingValueIsMethodAndPathAsTheSpecificationWritesThem(Endpoint $endpoint): void
    {
        self::assertMatchesRegularExpression('#^(GET|POST|PUT|DELETE) /[a-z][A-Za-z0-9/{}_-]*$#', $endpoint->value);
        self::assertSame($endpoint->value, $endpoint->method() . ' ' . $endpoint->pathTemplate());
    }

    public function testNoOperationIsRegisteredTwice(): void
    {
        $values = array_map(static fn (Endpoint $e): string => $e->value, Endpoint::cases());

        self::assertSame($values, array_values(array_unique($values)));
    }

    public function testOnlyTheShipmentTrackingAndCreateCommandOperationsAreV2(): void
    {
        $v2 = array_map(
            static fn (Endpoint $e): string => $e->value,
            array_filter(Endpoint::cases(), static fn (Endpoint $e): bool => $e->version() === 2),
        );

        self::assertSame([
            'POST /packages/validate',
            'POST /packages/calculate-price',
            'POST /packages',
            'PUT /packages/{package_id}',
            'GET /packages/{id}',
            'GET /packages',
            'GET /packages/{package_id}/tracking',
            'PUT /create-package-command/{uuid}',
        ], array_values($v2));
    }

    /**
     * @return iterable<string, array{Endpoint, list<string>}>
     */
    public static function pathParameters(): iterable
    {
        yield 'none' => [Endpoint::ListServices, []];
        yield 'one' => [Endpoint::UpdatePackage, ['package_id']];
        yield 'named id, not package_id' => [Endpoint::FetchPackage, ['id']];
        yield 'in the middle of a path' => [Endpoint::FetchLabel, ['package_id']];
        yield 'a command uuid' => [Endpoint::FetchOrderCommand, ['uuid']];
        yield 'after a fixed segment' => [Endpoint::RenameTrackedPackage, ['package_id']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('pathParameters')]
    public function testPathParametersAreTheOnesInTheTemplate(Endpoint $endpoint, array $expected): void
    {
        self::assertSame($expected, $endpoint->pathParameters());
    }

    public function testMediaTypeSpellsTheVersionTheWayFurgonetkaDoes(): void
    {
        self::assertSame('application/vnd.furgonetka.v1+json', Endpoint::mediaType(1));
        self::assertSame('application/vnd.furgonetka.v2+json', Endpoint::mediaType(2));
    }

    #[DataProvider('endpoints')]
    public function testAcceptAndContentTypeCarryTheRegisteredVersion(Endpoint $endpoint): void
    {
        $version = $endpoint->version();

        if ($version === null) {
            self::assertSame('application/json', $endpoint->accept());

            return;
        }

        self::assertStringContainsString(Endpoint::mediaType($version), $endpoint->accept());
        self::assertSame($endpoint->hasBody() ? Endpoint::mediaType($version) : null, $endpoint->contentType());

        foreach ([1, 2, 3] as $other) {
            if ($other !== $version) {
                self::assertStringNotContainsString(Endpoint::mediaType($other), $endpoint->accept());
            }
        }
    }

    public function testAJsonOperationAcceptsExactlyItsMediaType(): void
    {
        self::assertSame('application/vnd.furgonetka.v2+json', Endpoint::CreatePackage->accept());
        self::assertSame('application/vnd.furgonetka.v1+json', Endpoint::FetchDictionary->accept());
    }

    public function testAFileOperationAlsoAcceptsTheFile(): void
    {
        self::assertSame('application/pdf, text/plain, application/vnd.furgonetka.v1+json', Endpoint::FetchLabel->accept());
        self::assertSame('application/pdf, application/vnd.furgonetka.v1+json', Endpoint::FetchProtocol->accept());
    }

    public function testTheCompanyListIsPlainJson(): void
    {
        self::assertNull(Endpoint::ListCompanies->version());
        self::assertSame('application/json', Endpoint::ListCompanies->accept());
        self::assertNull(Endpoint::ListCompanies->contentType());
    }

    public function testTheFileUploadIsTheOnlyOperationWithAMultipartBody(): void
    {
        $uploads = array_filter(Endpoint::cases(), static fn (Endpoint $e): bool => $e->isUpload());

        self::assertSame([Endpoint::UploadFile], array_values($uploads));
        self::assertFalse(Endpoint::UploadFile->hasBody(), 'Its body is not JSON, so it has none in the sense of build().');
        self::assertNull(Endpoint::UploadFile->contentType(), 'The boundary is only known when the body is built.');
        self::assertSame('application/json', Endpoint::UploadFile->accept());
    }

    public function testTheFileAndHsCodeOperationsSendTheTokenAlthoughTheSpecificationListsNone(): void
    {
        $beyond = array_filter(Endpoint::cases(), static fn (Endpoint $e): bool => $e->sendsTokenBeyondSpec());

        self::assertSame([Endpoint::SubmitHsCodes, Endpoint::FetchHsCodes, Endpoint::UploadFile, Endpoint::FetchFileCommands], array_values($beyond));

        foreach ($beyond as $endpoint) {
            self::assertSame(EndpointAuth::User, $endpoint->auth(), $endpoint->value);
        }
    }

    public function testOnlySimulatingTrackingIsSandboxOnly(): void
    {
        $sandboxOnly = array_filter(Endpoint::cases(), static fn (Endpoint $e): bool => $e->isSandboxOnly());

        self::assertSame([Endpoint::SimulateTracking], array_values($sandboxOnly));
    }
}
