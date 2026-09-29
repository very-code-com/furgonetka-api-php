<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** A change of a carrier's price list that Furgonetka has announced. */
final class PricelistChange
{
    /**
     * @param \DateTimeImmutable|null $date    The day the new prices apply.
     * @param string|null             $service The carrier as sent.
     * @param string|null             $url     A page with the details.
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $date = null,
        public readonly ?string $service = null,
        public readonly ?string $url = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::dateTime($data, 'date'), Value::string($data, 'service'), Value::string($data, 'url'));
    }
}
