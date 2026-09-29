<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A phone number as Furgonetka reads it back (`phone_number_data`): the dialling prefix, the
 * number without it, and the country the prefix belongs to.
 *
 * The plain `phone` string of the answers is deprecated and not modelled. What you send is still
 * the plain `phone` (see {@see Pickup}); this is only for reading.
 */
final class PhoneNumber
{
    /**
     * @param string|null $prefix      Dialling prefix without the plus. Filled only when the number carried one Furgonetka could recognise.
     * @param string|null $number      The number without the prefix.
     * @param string|null $countryCode ISO 3166-1 alpha-2, detected from the prefix.
     */
    public function __construct(
        public readonly ?string $prefix = null,
        public readonly ?string $number = null,
        public readonly ?string $countryCode = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'prefix'),
            Value::string($data, 'phone_number'),
            Value::string($data, 'country_code'),
        );
    }

    /** The number with its prefix and a plus, when there is a prefix. */
    public function international(): ?string
    {
        if ($this->number === null) {
            return null;
        }

        return $this->prefix !== null ? '+' . $this->prefix . $this->number : $this->number;
    }
}
