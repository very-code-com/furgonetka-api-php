<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A day and a time window in which the courier could collect a shipment (`proposals[]` of
 * `fetchPickupProposals()`), and the same structure on a shipment that already has a pickup
 * (`pickup_date`).
 *
 * Pick one that is {@see self::$available} and turn it into the request with
 * {@see self::toPickupDate()}.
 *
 * `custom_description` (a text shown instead of the hours) is deprecated and not modelled.
 */
final class PickupProposal
{
    /**
     * @param string|null $date      The day, `YYYY-MM-DD`.
     * @param string|null $minTime   Start of the window, `HH:MM`.
     * @param string|null $maxTime   End of the window, `HH:MM`.
     * @param bool|null   $available Whether the slot can still be booked; not sent on a shipment's own `pickup_date`.
     * @param string|null $hash      MD5 of the date and the window; identifies the proposal.
     * @param string|null $info      Extra information (a shipment's own `pickup_date` only).
     */
    public function __construct(
        public readonly ?string $date = null,
        public readonly ?string $minTime = null,
        public readonly ?string $maxTime = null,
        public readonly ?bool $available = null,
        public readonly ?string $hash = null,
        public readonly ?string $info = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'date'),
            Value::string($data, 'min_time'),
            Value::string($data, 'max_time'),
            Value::bool($data, 'available'),
            Value::string($data, 'hash'),
            Value::string($data, 'info'),
        );
    }

    /**
     * The request form of this proposal, to book it with `orderPickup()`.
     *
     * @throws \LogicException when the proposal has no date.
     */
    public function toPickupDate(): PickupDate
    {
        if ($this->date === null) {
            throw new \LogicException('This proposal has no date, so it cannot be booked.');
        }

        return new PickupDate($this->date, $this->minTime, $this->maxTime, $this->hash);
    }
}
