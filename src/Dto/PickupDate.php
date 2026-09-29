<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\DateFormat;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * The day and time window in which the courier is to collect (`pickup_date` of a pickup command).
 *
 * Take it from a proposal of `fetchPickupProposals()`: `hash` is an MD5 of the date and the time
 * range and identifies the proposal you picked. Available for DHL, UPS, FedEx, DPD, Ambro Express,
 * Allegro, Poczta and ORLEN Paczka.
 *
 * The `custom_description` field of the specification is not modelled: it is deprecated.
 */
final class PickupDate
{
    /** `YYYY-MM-DD`, however it was given. */
    public readonly string $date;

    /**
     * @param string|\DateTimeInterface $date    The day of the pickup.
     * @param string|null               $minTime Start of the time window, `HH:MM`.
     * @param string|null               $maxTime End of the time window, `HH:MM`.
     * @param string|null               $hash    The proposal's hash, from the proposals answer.
     *
     * @throws \InvalidArgumentException when the date is empty.
     */
    public function __construct(
        string|\DateTimeInterface $date,
        public readonly ?string $minTime = null,
        public readonly ?string $maxTime = null,
        public readonly ?string $hash = null,
    ) {
        $this->date = DateFormat::date($date) ?? throw new \InvalidArgumentException('PickupDate: the date must not be empty.');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'date' => $this->date,
            'min_time' => $this->minTime,
            'max_time' => $this->maxTime,
            'hash' => $this->hash,
        ]);
    }
}
