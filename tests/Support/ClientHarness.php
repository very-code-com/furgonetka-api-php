<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Auth\TokenProviderInterface;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * A client wired to doubles: scripted transport, a static token, a recording logger and a sleeper
 * that only counts. Hand it the answers the API would give, in order, and read back what was sent.
 */
final class ClientHarness
{
    public const TOKEN = 'the-access-token';

    public readonly FakeTransport $transport;
    public readonly FakeSleeper $sleeper;
    public readonly RecordingLogger $logger;
    public readonly FurgonetkaApiClient $client;

    /** @param list<TransportResponse|\Throwable> $answers */
    public function __construct(array $answers = [], ?TokenProviderInterface $tokens = null, ?FurgonetkaApiConfig $config = null, ?PollingPolicy $polling = null)
    {
        $this->transport = new FakeTransport($answers);
        $this->sleeper = new FakeSleeper();
        $this->logger = new RecordingLogger();
        $this->client = new FurgonetkaApiClient(
            $config ?? TokenFactory::config(),
            $this->transport,
            $tokens ?? new StaticTokenProvider(self::TOKEN),
            $this->logger,
            null,
            $this->sleeper,
            $polling ?? new PollingPolicy(),
            $this->sleeper->clock(),
        );
    }

    /**
     * The requests that went out, as `METHOD url` lines (query strings included).
     *
     * @return list<string>
     */
    public function sent(): array
    {
        return array_map(static fn ($request): string => $request->method . ' ' . $request->url, $this->transport->requests);
    }

    /**
     * The JSON body of the n-th request (0 for the first).
     *
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        $body = $this->transport->requests[$index]->body ?? throw new \LogicException("Request #{$index} had no body.");
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \LogicException("Request #{$index} had no JSON object body.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** The exception a call throws, failing the test when it does not throw. */
    public static function thrownBy(callable $call): FurgonetkaApiException|\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        throw new \LogicException('Expected the call to throw.');
    }

    public static function request(): ShipmentRequest
    {
        return ShipmentFactory::request();
    }
}
