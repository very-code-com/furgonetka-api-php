<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * What Furgonetka answers when a return or a readdressing is requested: the UUID and the two
 * addresses where a person finishes the order.
 *
 * Nothing is paid or confirmed by the request itself. `paymentUrl` leads to the payment system and
 * `summaryUrl` to the confirmation of the order; both are null when there is nothing to pay or
 * confirm. The library does not wait for either, because it cannot know how long a person takes.
 * Neither call has been accepted on the sandbox (every shipment it was tried on was "not
 * possible"), so this shape is the specification's, not a captured body.
 */
final class PaymentRequest
{
    /**
     * @param string|null          $uuid       The UUID the request was sent under.
     * @param string|null          $paymentUrl Where to pay.
     * @param string|null          $summaryUrl Where to confirm the order.
     * @param array<string, mixed> $raw        The whole answer.
     */
    public function __construct(
        public readonly ?string $uuid = null,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $summaryUrl = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'uuid'),
            Value::string($data, 'paymentUrl'),
            Value::string($data, 'summaryUrl'),
            $data,
        );
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
