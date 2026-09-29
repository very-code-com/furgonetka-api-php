<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One page of the webhook call log (`GET /account/settings/notification/webhook/logs`).
 *
 * The next page is asked for with the last entry of this one:
 * `$client->listWebhookLogs($page->nextCursor())`. The order and the paging have not been seen on a
 * real log (the sandbox account has none), so they follow the specification.
 *
 * @implements \IteratorAggregate<int, WebhookLog>
 */
final class WebhookLogPage implements \Countable, \IteratorAggregate
{
    /**
     * @param list<WebhookLog>        $logs        The entries of this page.
     * @param \DateTimeImmutable|null $lastSuccess When a call last succeeded, within the last 30 days.
     * @param bool                    $isNextPage  Whether there are more entries.
     * @param array<string, mixed>    $raw         The whole answer.
     */
    public function __construct(
        public readonly array $logs = [],
        public readonly ?\DateTimeImmutable $lastSuccess = null,
        public readonly bool $isNextPage = false,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(WebhookLog::fromArray(...), Value::objectList($data, 'logs')),
            Value::dateTime($data, 'lastSuccess'),
            Value::bool($data, 'isNextPage') ?? false,
            $data,
        );
    }

    /** Number of entries on this page. */
    public function count(): int
    {
        return count($this->logs);
    }

    /** @return \ArrayIterator<int, WebhookLog> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->logs);
    }

    /** The entry to pass to `listWebhookLogs()` for the next page; null when this is the last page. */
    public function nextCursor(): ?WebhookLog
    {
        return $this->isNextPage && $this->logs !== [] ? $this->logs[array_key_last($this->logs)] : null;
    }

    /**
     * The whole answer as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
