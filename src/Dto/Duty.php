<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\DutyDocumentsOwner;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * Data for customs clearance (`duty`).
 *
 * Required for shipments to the countries of {@see \VeryCodeCom\FurgonetkaApi\Internal\Validator\ShipmentValidator::DUTY_COUNTRIES}
 * (and any country whose entry in `GET /configuration/allowed-countries` has the `duty` flag). The
 * specification also names UPS, FedEx, Meest, GLS and NovaPost as carriers that need it; on the
 * sandbox those carriers accepted a domestic shipment and one to Germany without it, so the
 * requirement follows the destination, not the carrier.
 *
 * A sale needs the sender's EORI number.
 */
final class Duty
{
    /**
     * @param list<ExportItem>        $content        The goods.
     * @param DutyType|null           $type           Purpose of the shipment.
     * @param DutyDocumentsOwner|null $documentsOwner Who provides the customs documents; Furgonetka does when null.
     * @param list<string>            $documentsUuids UUIDs of documents you uploaded (invoice, statement, other), up to five, FedEx and UPS.
     *                                                Only read when `$documentsOwner` is `Client`. Upload with `uploadFile()`; which of the two UUIDs it
     *                                                answers a shipment takes is not settled (docs/FURGONETKA-NOTES.md, Files).
     * @param float|null              $transportPrice Transport cost.
     * @param string|null             $eori           Sender's EORI number; required for a sale.
     * @param string|null             $receiverEori   Receiver's EORI number.
     * @param string|null             $vatNumber      VAT number.
     * @param string|null             $nip            Sender's NIP.
     * @param string|null             $regon          Sender's REGON.
     * @param string|null             $pesel          Sender's PESEL.
     */
    public function __construct(
        public readonly array $content = [],
        public readonly ?DutyType $type = null,
        public readonly ?DutyDocumentsOwner $documentsOwner = null,
        public readonly array $documentsUuids = [],
        public readonly ?float $transportPrice = null,
        public readonly ?string $eori = null,
        public readonly ?string $receiverEori = null,
        public readonly ?string $vatNumber = null,
        public readonly ?string $nip = null,
        public readonly ?string $regon = null,
        public readonly ?string $pesel = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'content' => array_map(static fn (ExportItem $item): array => $item->toArray(), $this->content),
            'type' => $this->type?->value,
            'documents_owner' => $this->documentsOwner?->value,
            'documents_uuids' => $this->documentsUuids,
            'transport_price' => $this->transportPrice,
            'eori' => $this->eori,
            'receiver_eori' => $this->receiverEori,
            'vat_number' => $this->vatNumber,
            'nip' => $this->nip,
            'regon' => $this->regon,
            'pesel' => $this->pesel,
        ]);
    }
}
