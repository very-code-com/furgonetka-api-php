<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One entry of a parcel's tracking history.
 *
 * `state` is Furgonetka's own normalised state and the thing to branch on; `status` is the
 * carrier's wording for the same moment. It is free text in the language of the carrier (the
 * sandbox answers in Polish whatever `X-Language` says), so show it, never match on it.
 */
final class TrackingEvent
{
    /**
     * @param TrackingState|null      $state        Normalised state; null when it is not a member.
     * @param string|null             $stateValue   State as sent.
     * @param string|null             $status       The carrier's description of the event.
     * @param \DateTimeImmutable|null $datetime     When it happened.
     * @param string|null             $branch       Depot where the parcel was; often empty.
     * @param string|null             $courierPhone Phone of the delivering courier, when the carrier gives it.
     */
    public function __construct(
        public readonly ?TrackingState $state = null,
        public readonly ?string $stateValue = null,
        public readonly ?string $status = null,
        public readonly ?\DateTimeImmutable $datetime = null,
        public readonly ?string $branch = null,
        public readonly ?string $courierPhone = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            state: Value::enum($data, 'state', TrackingState::class),
            stateValue: Value::string($data, 'state'),
            status: Value::string($data, 'status'),
            datetime: Value::dateTime($data, 'datetime'),
            branch: Value::string($data, 'branch'),
            courierPhone: Value::string($data, 'courierPhone'),
        );
    }
}
