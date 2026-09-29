<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The link to a shipment form with the receiver's data filled in (`POST /packages/send-to-me-link`).
 *
 * The data travels in the link itself, as Base64 in `sendToMeData`, and is not secret: it is what
 * you sent. Send the link to the receiver; whoever opens it fills in the rest of the shipment.
 */
final class SendToMeLink
{
    /**
     * @param string|null          $url The link.
     * @param array<string, mixed> $raw The whole answer.
     */
    public function __construct(
        public readonly ?string $url = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::string($data, 'url'), $data);
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
