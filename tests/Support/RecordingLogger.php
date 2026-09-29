<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use Psr\Log\AbstractLogger;

/** A PSR-3 logger that keeps every record, so a test can read back what was logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * The native type of $message is `mixed` on purpose: psr/log 2.x and 3.x declare
     * `string|\Stringable` and older releases declare nothing, and `mixed` is a valid override of
     * all of them, so neither PHP nor an IDE that resolves another release can call it incompatible.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function log($level, mixed $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** True when a record of this level has a message that contains the text. */
    public function has(string $level, string $text): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $text)) {
                return true;
            }
        }

        return false;
    }

    /** Everything that was logged as one string, messages and contexts, for a "no secret in here" check. */
    public function dump(): string
    {
        return print_r($this->records, true);
    }
}
