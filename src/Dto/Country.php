<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** A country a shipment can be sent from or to, with the flag that says whether it needs customs data. */
final class Country
{
    /**
     * @param string|null $name Name in the language of the request.
     * @param string|null $code ISO 3166-1 alpha-2.
     * @param bool|null   $duty True when a shipment to this country needs `duty`.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?bool $duty = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::string($data, 'name'), Value::string($data, 'code'), Value::bool($data, 'duty'));
    }
}
