<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * Extra financial data for the carrier (`payer`). At the moment only the cost centre.
 *
 * The specification's `mpk` field of the price quote was removed on 2026-07-27; the request side
 * kept `cost_center`.
 */
final class Payer
{
    /**
     * @param string|null $costCenter Cost centre (MPK).
     */
    public function __construct(public readonly ?string $costCenter = null)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact(['cost_center' => $this->costCenter]);
    }
}
