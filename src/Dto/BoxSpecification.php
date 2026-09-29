<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** One size of compartment in a parcel locker. */
final class BoxSpecification
{
    /**
     * @param string|null $gauge  Size class, for instance "A".
     * @param int|null    $width  Width.
     * @param int|null    $height Height.
     * @param int|null    $depth  Depth.
     */
    public function __construct(
        public readonly ?string $gauge = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $depth = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'gauge'),
            Value::int($data, 'width'),
            Value::int($data, 'height'),
            Value::int($data, 'depth'),
        );
    }
}
