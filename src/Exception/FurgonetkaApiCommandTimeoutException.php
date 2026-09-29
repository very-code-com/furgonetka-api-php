<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown when an asynchronous command (order, pickup, cancel, documents) did not reach a final
 * state within the polling limit.
 *
 * The command is not lost: Furgonetka keeps working on it. Read its result later with the
 * matching `fetch*Command($uuid)` method instead of submitting it again, which could order the
 * shipments twice.
 */
class FurgonetkaApiCommandTimeoutException extends FurgonetkaApiException
{
    /**
     * @param string      $commandUuid UUID the command was submitted under.
     * @param string|null $lastStatus  Last status Furgonetka reported, as sent (`queueing`,
     *                                 `running`, ...); kept as a string so a status added after
     *                                 this release is still shown verbatim.
     */
    public function __construct(
        string $message,
        private readonly string $commandUuid,
        private readonly ?string $lastStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function commandUuid(): string
    {
        return $this->commandUuid;
    }

    public function lastStatus(): ?string
    {
        return $this->lastStatus;
    }
}
