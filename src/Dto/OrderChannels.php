<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * Where a change of state of an ordered shipment is announced: which of the four channels are on.
 * A channel that is null is not available for that state.
 */
final class OrderChannels
{
    public function __construct(
        public readonly ?bool $sms = null,
        public readonly ?bool $email = null,
        public readonly ?bool $webhook = null,
        public readonly ?bool $push = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::bool($data, 'sms'), Value::bool($data, 'email'), Value::bool($data, 'webhook'), Value::bool($data, 'push'));
    }
}
