<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;

final class CommandStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}> status as Furgonetka sends it, whether it is final
     */
    public static function statuses(): iterable
    {
        yield 'queueing' => ['queueing', false];
        yield 'running' => ['running', false];
        yield 'successful' => ['successful', true];
        yield 'partial_success' => ['partial_success', true];
        yield 'error' => ['error', true];
        yield 'cancelled (not in the documentation, seen on the sandbox)' => ['cancelled', true];
    }

    #[DataProvider('statuses')]
    public function testTheSixStatusesAndWhichOnesAreFinal(string $value, bool $final): void
    {
        $status = CommandStatus::from($value);

        self::assertSame($final, $status->isTerminal());
    }

    public function testThereAreExactlySixStatuses(): void
    {
        self::assertSame(
            ['queueing', 'running', 'successful', 'partial_success', 'error', 'cancelled'],
            array_map(static fn (CommandStatus $status): string => $status->value, CommandStatus::cases()),
        );
    }

    #[DataProvider('statusesAddedLater')]
    public function testAStatusAddedLaterReadsAsNull(string $value): void
    {
        self::assertNull(CommandStatus::tryFrom($value));
    }

    /** @return iterable<string, array{string}> */
    public static function statusesAddedLater(): iterable
    {
        yield 'a new word' => ['archived'];
        yield 'a known one in the wrong case' => ['Successful'];
        yield 'empty' => [''];
    }
}
