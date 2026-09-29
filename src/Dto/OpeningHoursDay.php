<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** The hours a point is open on one day, as "HH:MM" text. */
final class OpeningHoursDay
{
    /**
     * @param string|null $start When it opens, for instance "08:00".
     * @param string|null $end   When it closes, for instance "16:00".
     */
    public function __construct(
        public readonly ?string $start = null,
        public readonly ?string $end = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::string($data, 'start_hour'), Value::string($data, 'end_hour'));
    }
}
