<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * Cash on delivery (`additional_services.cod`).
 *
 * The amount is for the whole shipment, not per parcel. The sandbox asks for the account owner's
 * name as well (`/additional_services/cod/name`), which the specification does not list as
 * required; it stays optional here because the answer may depend on the carrier.
 *
 * The amount must be covered by the declared value of the parcels: DHL, FedEx and Poczta Polska
 * reject a COD above the sum of `Parcel::$value` (checked on the sandbox on 2026-09-29), and a
 * shipment with no declared value at all is rejected the same way. DPD does not check it.
 */
final class Cod
{
    /**
     * @param float       $amount   Amount to collect, in PLN unless `$currency` says otherwise.
     * @param string|null $currency Currency of the COD.
     * @param bool|null   $express  Express COD: the amount reaches the sender one working day after delivery. DPD, FedEx, InPost Kurier.
     * @param string|null $iban     Bank account the amount is paid into.
     * @param string|null $name     Owner of that account.
     * @param string|null $swift    SWIFT/BIC code.
     */
    public function __construct(
        public readonly float $amount,
        public readonly ?string $currency = null,
        public readonly ?bool $express = null,
        public readonly ?string $iban = null,
        public readonly ?string $name = null,
        public readonly ?string $swift = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'express' => $this->express,
            'iban' => $this->iban,
            'name' => $this->name,
            'swift' => $this->swift,
        ]);
    }
}
