<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * A scripted transport: hands back queued responses in order and records what it was asked to
 * send, so everything above the transport can be exercised without touching the network.
 *
 * This is the pattern the library is designed for, {@see TransportInterface} exists precisely so
 * that a fake can be dropped in, and it is what the documentation shows for testing an
 * integration built on top of this client.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<TransportRequest> Every request the code under test made, in order. */
    public array $requests = [];

    /** @var list<TransportResponse|\Throwable> */
    private array $queue;

    /** @param list<TransportResponse|\Throwable> $queue Responses to return, or throwables to raise. */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /** A transport that answers with one JSON body. */
    public static function json(string $body, int $status = 200): self
    {
        return new self([self::jsonResponse($body, $status)]);
    }

    /** A JSON answer as Furgonetka sends it, for building a queue. */
    public static function jsonResponse(string $body, int $status = 200): TransportResponse
    {
        return new TransportResponse($status, $body, ['content-type' => 'application/json']);
    }

    /** Queue one more answer. */
    public function push(TransportResponse|\Throwable $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new FurgonetkaApiTransportException(sprintf(
                'FakeTransport ran out of scripted responses at request #%d (%s).',
                count($this->requests),
                $request->describe(),
            ));
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** Number of scripted answers not used yet. */
    public function remaining(): int
    {
        return count($this->queue);
    }

    /** The most recent request, for assertions about what went out. */
    public function lastRequest(): TransportRequest
    {
        return $this->requests[count($this->requests) - 1]
            ?? throw new \LogicException('FakeTransport has not received a request yet.');
    }

    /**
     * The form fields of the most recent request, decoded.
     *
     * @return array<string, string>
     */
    public function lastForm(): array
    {
        parse_str($this->lastRequest()->body ?? '', $fields);

        /** @var array<string, string> $fields */
        return $fields;
    }
}
