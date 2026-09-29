<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A place on the map, in degrees: what a point sits at and what a search is centred on.
 *
 * Furgonetka sends the centre of a search by phrase or address as text ("52.2301600000000"), and
 * the coordinates of a point as numbers; both are read as floats.
 */
final class Coordinates
{
    /**
     * @param float|null $latitude  Degrees north.
     * @param float|null $longitude Degrees east.
     */
    public function __construct(
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::float($data, 'latitude'), Value::float($data, 'longitude'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact(['latitude' => $this->latitude, 'longitude' => $this->longitude]);
    }
}
