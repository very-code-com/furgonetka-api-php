<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** The pickup proposals for one shipment (`packages[]` of `fetchPickupProposals()`). */
final class PickupProposalGroup
{
    /**
     * @param string|null          $packageId The shipment.
     * @param list<PickupProposal> $proposals The slots, available or not.
     */
    public function __construct(
        public readonly ?string $packageId = null,
        public readonly array $proposals = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'package_id'),
            array_map(PickupProposal::fromArray(...), Value::objectList($data, 'proposals')),
        );
    }

    /**
     * The slots that can still be booked.
     *
     * @return list<PickupProposal>
     */
    public function available(): array
    {
        return array_values(array_filter($this->proposals, static fn (PickupProposal $p): bool => $p->available !== false));
    }

    /** The first slot that can still be booked, or null when there is none. */
    public function first(): ?PickupProposal
    {
        return $this->available()[0] ?? null;
    }
}
