<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * One kind of goods in a shipment that goes through customs (`duty.content[]`).
 *
 * The specification lists eight fields. The sandbox demands more for a shipment to Great Britain
 * (checked on 2026-09-29): the composition and the purpose of the goods, each at least ten
 * characters, and an invoice number for a sale. Those, and the English variants, are the fields of
 * Furgonetka's own `exportItem` dictionary, so they are modelled here although the specification
 * leaves them out.
 *
 * The description is Polish, or English when the request carries `X-Language: en_GB`, which this
 * library sends by default; the `*En` fields exist for carriers that want both.
 */
final class ExportItem
{
    /**
     * @param string|null $nameMaterial       Description of the goods, at least ten characters. As precise as you can make it.
     * @param string|null $nameMaterialEn     The same in English.
     * @param string|null $itemComposition    What the goods are made of, at least ten characters.
     * @param string|null $itemCompositionEn  The same in English.
     * @param string|null $itemPurpose        What the goods are for, at least ten characters.
     * @param string|null $itemPurposeEn      The same in English.
     * @param int|null    $itemsCount         Number of pieces.
     * @param float|null  $itemPrice          Price of one piece, PLN.
     * @param string|null $countryCode        Country of production, ISO 3166-1 alpha-2.
     * @param string|null $invoiceNo          Invoice number; required for a sale to Great Britain.
     * @param float|null  $itemWeight         Weight of one piece, kg.
     * @param string|null $tariff             Customs tariff code (ISZTAR), or the HTS code for the United States.
     * @param string|null $mid                MID code, required for the United States.
     */
    public function __construct(
        public readonly ?string $nameMaterial = null,
        public readonly ?string $nameMaterialEn = null,
        public readonly ?string $itemComposition = null,
        public readonly ?string $itemCompositionEn = null,
        public readonly ?string $itemPurpose = null,
        public readonly ?string $itemPurposeEn = null,
        public readonly ?int $itemsCount = null,
        public readonly ?float $itemPrice = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $invoiceNo = null,
        public readonly ?float $itemWeight = null,
        public readonly ?string $tariff = null,
        public readonly ?string $mid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Payload::compact([
            'name_material' => $this->nameMaterial,
            'name_material_en' => $this->nameMaterialEn,
            'item_composition' => $this->itemComposition,
            'item_composition_en' => $this->itemCompositionEn,
            'item_purpose' => $this->itemPurpose,
            'item_purpose_en' => $this->itemPurposeEn,
            'items_count' => $this->itemsCount,
            'item_price' => $this->itemPrice,
            'country_code' => $this->countryCode,
            'invoice_no' => $this->invoiceNo,
            'item_weight' => $this->itemWeight,
            'tariff' => $this->tariff,
            'mid' => $this->mid,
        ]);
    }
}
