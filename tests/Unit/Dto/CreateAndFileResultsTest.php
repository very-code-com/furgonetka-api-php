<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Dto\ApiError;
use VeryCodeCom\FurgonetkaApi\Dto\CreatePackageCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\FileUploadResult;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The results of creating a shipment as a command and of a file upload, on the bodies the sandbox
 * gave. The redirect answer of the create command is built from the specification: the sandbox
 * never sent one.
 */
final class CreateAndFileResultsTest extends TestCase
{
    // -- Creating a shipment as a command ------------------------------------------------------

    public function testAQueueingCreationHasNoShipmentYet(): void
    {
        $result = CreatePackageCommandResult::fromArray(Fixtures::json('create/queueing'));

        self::assertSame(CommandStatus::Queueing, $result->status);
        self::assertFalse($result->isFinished());
        self::assertNull($result->packageId);
        self::assertNull($result->redirectUrl);
        self::assertFalse($result->needsRedirect());
    }

    public function testASuccessfulCreationNamesTheShipmentAsText(): void
    {
        $result = CreatePackageCommandResult::fromArray(Fixtures::json('create/successful'));

        self::assertTrue($result->isSuccessful());
        self::assertSame('21354137', $result->packageId, 'The id comes as a number and is text here.');
        self::assertSame('39512680-0b59-4297-87c2-1209a8986323', $result->uuid);
        self::assertFalse($result->needsRedirect());
        self::assertSame([], $result->errors);
    }

    public function testACreationThatEndsInABrowserHasARedirectUrl(): void
    {
        // Shape from the specification (CreatePackageCommandGetResponse), not a captured body.
        $result = CreatePackageCommandResult::fromArray(['status' => 'successful', 'uuid' => 'u', 'package_id' => null, 'redirect_url' => 'https://furgonetka.pl/potwierdzenie/logo', 'errors' => []]);

        self::assertTrue($result->needsRedirect());
        self::assertSame('https://furgonetka.pl/potwierdzenie/logo', $result->redirectUrl);
        self::assertNull($result->packageId);
    }

    public function testAFailedCreationHasErrorsWhosePathsAreIndexedNotNamed(): void
    {
        $result = CreatePackageCommandResult::fromArray(Fixtures::json('create/error-indexed-fields'));

        self::assertTrue($result->hasFailed());
        self::assertSame(['/0', '/1', '/2'], array_map(static fn (ApiError $e): ?string => $e->path, $result->errors));
        self::assertSame(['isEmpty', 'isEmpty', '0'], array_map(static fn (ApiError $e): ?string => $e->code, $result->errors));
        self::assertSame([], $result->failedPackageIds(), 'These paths are not the /packages/id/{id} of an order.');
        self::assertCount(3, $result->generalErrors());
        self::assertNull($result->packageId);
    }

    public function testTheErrorsOfAShipmentThatFurgonetkaRefusesNameTheFieldUnderAnIndex(): void
    {
        $result = CreatePackageCommandResult::fromArray(Fixtures::json('create/error-postcode'));

        self::assertTrue($result->hasFailed());
        self::assertSame(['/0/postcode', '/0/city'], array_map(static fn (ApiError $e): ?string => $e->path, $result->errors));
        self::assertSame(['unavailable', 'notExists'], array_map(static fn (ApiError $e): ?string => $e->code, $result->errors));
    }

    // -- File uploads ----------------------------------------------------------------------------

    /**
     * The entries of an answer of `GET /file-command`.
     *
     * @return list<FileUploadResult>
     */
    private static function uploads(string $fixture): array
    {
        $entries = Fixtures::json($fixture)['responses'] ?? null;
        self::assertIsArray($entries);
        $results = [];

        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            /** @var array<string, mixed> $entry */
            $results[] = FileUploadResult::fromArray($entry);
        }

        return $results;
    }

    public function testAQueueingUploadHasNoFileUuidYet(): void
    {
        $result = self::uploads('files/queueing')[0];

        self::assertSame(CommandStatus::Queueing, $result->status);
        self::assertFalse($result->isFinished());
        self::assertNull($result->fileUuid);
        self::assertSame('fdc51fd4-20c8-4418-91f8-3444ed91952e', $result->uuid);
    }

    public function testASuccessfulUploadHasTwoDifferentUuids(): void
    {
        $result = self::uploads('files/successful')[0];

        self::assertTrue($result->isSuccessful());
        self::assertSame('fdc51fd4-20c8-4418-91f8-3444ed91952e', $result->uuid, 'The UUID of the upload, chosen by the caller.');
        self::assertSame('426a16fb-a37f-4a82-b0f1-e85705d64df6', $result->fileUuid, 'The UUID of the stored file, given by Furgonetka.');
        self::assertNotSame($result->uuid, $result->fileUuid);
    }

    public function testSeveralUploadsComeInAnyOrderWithTheirOwnStates(): void
    {
        $results = self::uploads('files/two-unordered');

        self::assertSame(['queueing', 'successful'], array_map(static fn (FileUploadResult $r): ?string => $r->statusValue, $results), 'Not the order they were asked for in.');
        self::assertNull($results[0]->fileUuid);
        self::assertNotNull($results[1]->fileUuid);
    }

    public function testFiveStoredFilesOfEveryKindAreAllSuccessful(): void
    {
        $results = self::uploads('files/five-successful');

        self::assertCount(5, $results);

        foreach ($results as $result) {
            self::assertTrue($result->isSuccessful());
            self::assertSame([], $result->errors);
            self::assertNotNull($result->fileUuid);
        }

        self::assertCount(5, array_unique(array_map(static fn (FileUploadResult $r): ?string => $r->fileUuid, $results)));
    }

    public function testAnEmptyEntryIsAllNulls(): void
    {
        $result = FileUploadResult::fromArray([]);

        self::assertNull($result->uuid);
        self::assertNull($result->fileUuid);
        self::assertNull($result->status);
        self::assertFalse($result->isFinished());
    }
}
