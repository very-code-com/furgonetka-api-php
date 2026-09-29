<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Dto\DocumentsCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\OrderCommandResult;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Internal\Command\CommandRunner;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeSleeper;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The wait for a command, on the real statuses of the sandbox and a sleeper that only counts.
 */
final class CommandRunnerTest extends TestCase
{
    private const UUID = '15df345d-6afa-42dc-9de5-c99eed4e7445';

    private FakeSleeper $sleeper;
    private CommandRunner $runner;

    protected function setUp(): void
    {
        $this->sleeper = new FakeSleeper();
        $this->runner = new CommandRunner($this->sleeper, $this->sleeper->clock());
    }

    private static function documents(string $fixture): DocumentsCommandResult
    {
        return DocumentsCommandResult::fromArray(Fixtures::json('commands/' . $fixture));
    }

    /**
     * A read that gives the results in turn, and counts.
     *
     * @param list<DocumentsCommandResult|\Throwable> $results
     * @return array{\Closure(): DocumentsCommandResult, \stdClass}
     */
    private static function reads(array $results): array
    {
        $state = new \stdClass();
        $state->count = 0;

        return [
            static function () use ($results, $state): DocumentsCommandResult {
                $next = $results[min($state->count, count($results) - 1)];
                $state->count++;

                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            },
            $state,
        ];
    }

    public function testAFinishedCommandIsReturnedAtOnceWithoutSleeping(): void
    {
        [$read, $state] = self::reads([self::documents('documents-successful')]);

        $result = $this->runner->wait(self::UUID, $read, new PollingPolicy());

        self::assertTrue($result->isSuccessful());
        self::assertSame(1, $state->count);
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testItReadsUntilTheStatusIsFinalAndWaitsBetweenTheReads(): void
    {
        // The three statuses the sandbox gave for a documents command, in the order it gave them.
        [$read, $state] = self::reads([self::documents('documents-queueing'), self::documents('documents-running'), self::documents('documents-successful')]);

        $result = $this->runner->wait(self::UUID, $read, new PollingPolicy());

        self::assertTrue($result->isSuccessful());
        self::assertSame(3, $state->count);
        self::assertSame([1.0, 1.0], $this->sleeper->sleeps);
    }

    /**
     * @return iterable<string, array{string, bool}> fixture, whether it is final
     */
    public static function allFiveStatuses(): iterable
    {
        yield 'queueing' => ['commands/documents-queueing', false];
        yield 'running' => ['commands/documents-running', false];
        yield 'successful' => ['commands/documents-successful', true];
        // Neither of these was seen on the sandbox; the fixtures are Furgonetka's own documented example and its partial variant.
        yield 'error' => ['commands/documented/order-error', true];
        yield 'partial_success' => ['commands/documented/order-partial-success', true];
    }

    #[DataProvider('allFiveStatuses')]
    public function testEachOfTheFiveStatusesIsHandled(string $fixture, bool $final): void
    {
        $first = OrderCommandResult::fromArray(Fixtures::json($fixture));
        $successful = OrderCommandResult::fromArray(Fixtures::json('commands/order-successful'));
        $results = [$first, $successful];
        $reads = 0;
        $read = static function () use ($results, &$reads): OrderCommandResult {
            return $results[min($reads++, 1)];
        };

        $returned = $this->runner->wait(self::UUID, $read, new PollingPolicy());

        self::assertSame($final ? 1 : 2, $reads, $final ? 'A final status ends the wait.' : 'A status that is not final is read again.');
        self::assertSame($final ? $first : $successful, $returned);
    }

    public function testTheWaitGrowsWithABackoffUpToTheCeiling(): void
    {
        [$read] = self::reads([...array_fill(0, 6, self::documents('documents-running')), self::documents('documents-successful')]);

        $this->runner->wait(self::UUID, $read, new PollingPolicy(interval: 1.0, backoff: 2.0, maxInterval: 5.0, timeout: 100.0));

        self::assertSame([1.0, 2.0, 4.0, 5.0, 5.0, 5.0], $this->sleeper->sleeps);
    }

    public function testACommandThatNeverFinishesTimesOutAfterExactlyTheTimeoutAndCarriesItsUuid(): void
    {
        [$read, $state] = self::reads([self::documents('documents-running')]);

        try {
            $this->runner->wait(self::UUID, $read, new PollingPolicy(interval: 1.0, timeout: 5.0));
            self::fail('Expected a FurgonetkaApiCommandTimeoutException.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame(self::UUID, $e->commandUuid());
            self::assertSame('running', $e->lastStatus());
            self::assertStringContainsString('did not finish within 5 seconds', $e->getMessage());
            self::assertStringContainsString('instead of submitting it again', $e->getMessage());
        }

        self::assertSame(5.0, $this->sleeper->total());
        self::assertSame(6, $state->count, 'One read at the start and one after each of the five waits.');
    }

    public function testTheLastWaitIsCutShortSoThatTheCommandIsReadOnceMoreAtTheDeadline(): void
    {
        [$read, $state] = self::reads([self::documents('documents-queueing'), self::documents('documents-running'), self::documents('documents-successful')]);

        $result = $this->runner->wait(self::UUID, $read, new PollingPolicy(interval: 4.0, timeout: 6.0));

        self::assertSame([4.0, 2.0], $this->sleeper->sleeps);
        self::assertSame(3, $state->count);
        self::assertTrue($result->isSuccessful(), 'The read at the deadline found it done.');
    }

    public function testSlowReadsUseUpTheTimeoutToo(): void
    {
        $sleeper = $this->sleeper;
        $state = new \stdClass();
        $state->count = 0;
        $read = static function () use ($sleeper, $state): DocumentsCommandResult {
            $state->count++;
            $sleeper->advance(10.0);

            return self::documents('documents-running');
        };

        $this->expectException(FurgonetkaApiCommandTimeoutException::class);

        try {
            $this->runner->wait(self::UUID, $read, new PollingPolicy(timeout: 5.0));
        } finally {
            self::assertSame(1, $state->count);
            self::assertSame([], $this->sleeper->sleeps, 'No time is left to wait.');
        }
    }

    public function testAStatusThatIsNotOneOfTheFiveIsNotTakenForAResult(): void
    {
        $unknown = DocumentsCommandResult::fromArray(['uuid' => self::UUID, 'status' => 'archived', 'errors' => []]);
        [$read, $state] = self::reads([$unknown]);

        try {
            $this->runner->wait(self::UUID, $read, new PollingPolicy(timeout: 3.0));
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame('archived', $e->lastStatus(), 'The raw status is visible in the exception.');
            self::assertStringContainsString('last status: archived', $e->getMessage());
        }

        self::assertGreaterThan(1, $state->count);
    }

    public function testANetworkFailureOnAReadCarriesTheUuidAndIsTheSameException(): void
    {
        $failure = new FurgonetkaApiTransportException('Connection timed out');
        [$read] = self::reads([self::documents('documents-queueing'), $failure]);

        try {
            $this->runner->wait(self::UUID, $read, new PollingPolicy());
            self::fail('Expected a FurgonetkaApiTransportException.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame($failure, $e);
            self::assertSame(self::UUID, $e->commandUuid());
        }
    }

    public function testANetworkFailureOnTheFirstReadCarriesTheUuidToo(): void
    {
        [$read] = self::reads([new FurgonetkaApiTransportException('DNS failure')]);

        $e = null;
        try {
            $this->runner->wait(self::UUID, $read, new PollingPolicy());
        } catch (FurgonetkaApiTransportException $e) {
            // Checked below.
        }

        self::assertNotNull($e);
        self::assertSame(self::UUID, $e->commandUuid());
    }

    public function testAnErrorThatIsNotAboutTheNetworkPassesThroughUntouched(): void
    {
        $refused = new FurgonetkaApiBadRequestException('Operation not exists.', 400, []);
        [$read] = self::reads([$refused]);

        try {
            $this->runner->wait(self::UUID, $read, new PollingPolicy());
            self::fail('Expected a FurgonetkaApiBadRequestException.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame($refused, $e);
        }
    }
}
