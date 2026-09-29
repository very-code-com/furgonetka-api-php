<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * Where a change of state of a tracked shipment is announced: the webhook and the mobile app. A
 * channel that is null is not available for that state.
 */
final class TrackingChannels
{
    public function __construct(
        public readonly ?bool $webhook = null,
        public readonly ?bool $push = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::bool($data, 'webhook'), Value::bool($data, 'push'));
    }
}
