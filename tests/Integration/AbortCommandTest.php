<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Command\CommandType;
use VeryCodeCom\FurgonetkaApi\Enum\CsvRange;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;

/**
 * Aborting a command (plan W11), on CSV exports because they are harmless. A command can only be aborted
 * while it is queued, and it leaves the queue within a second or two, so whether an abort lands depends on
 * timing: the tests try a few times and skip when it never does.
 */
final class AbortCommandTest extends SandboxTestCase
{
    private const ATTEMPTS = 12;

    private static ?FurgonetkaApiClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    /**
     * Submit exports and abort each at once until one abort is accepted; returns the UUID of that one.
     * An abort that arrives after the command started is refused with `wrongState`.
     */
    private function abortedExport(CommandType $type): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $uuid = $this->client()->submitCsvExport(CsvRange::Last30Days);

            try {
                $this->client()->abortCommand($uuid, $type);

                return $uuid;
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('wrongState'), $e->getMessage());
            }
        }

        self::markTestSkipped('No abort landed while the command was queued in ' . self::ATTEMPTS . ' tries; the sandbox was too quick.');
    }

    public function testACommandAbortedWhileQueuedEndsAsCancelled(): void
    {
        $uuid = $this->abortedExport(CommandType::Generic);

        $result = $this->client()->fetchCsvExport($uuid);

        self::assertSame(CommandStatus::Cancelled, $result->status);
        self::assertTrue($result->isCancelled());
        self::assertTrue($result->isFinished());
        self::assertNull($result->url);
    }

    public function testTheEndpointOfTheCommandTypeAbortsToo(): void
    {
        $uuid = $this->abortedExport(CommandType::CsvExport);

        self::assertTrue($this->client()->fetchCsvExport($uuid)->isCancelled());
    }

    public function testWaitingForACancelledCommandReturnsItAtOnce(): void
    {
        $uuid = $this->abortedExport(CommandType::Generic);

        // The same UUID again: Furgonetka says the command exists, which the client takes as "already
        // submitted" for a UUID the caller chose, and the wait ends on the cancelled status.
        $result = $this->client()->exportPackagesCsv(CsvRange::Last30Days, uuid: $uuid);

        self::assertTrue($result->isCancelled());
        self::assertSame($uuid, $result->uuid);
    }

    public function testAFinishedCommandCannotBeAborted(): void
    {
        $result = $this->client()->exportPackagesCsv(CsvRange::Today);

        foreach ([CommandType::Generic, CommandType::CsvExport] as $type) {
            try {
                $this->client()->abortCommand((string) $result->uuid, $type);
                self::fail('A finished command cannot be aborted.');
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('wrongState'), $e->getMessage());
                self::assertSame(['/uuid'], $e->getFieldPaths());
            }
        }
    }

    public function testAnUnknownCommandCannotBeAborted(): void
    {
        try {
            $this->client()->abortCommand(Uuid::v4());
            self::fail('There is no such command.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'), $e->getMessage());
            self::assertSame(['/uuid'], $e->getFieldPaths());
        }
    }
}
