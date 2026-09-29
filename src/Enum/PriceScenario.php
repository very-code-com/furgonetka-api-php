<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * How Furgonetka arrived at the price of a return or a readdressing (`scenario`).
 *
 * The specification names one value. It says nothing about what the price is in that case; the name
 * suggests a DHL Freight shipment that is priced by agreement, see
 * {@see \VeryCodeCom\FurgonetkaApi\Dto\PriceEstimate::requiresIndividualPricing()}.
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum PriceScenario: string
{
    /** Priced individually, DHL Freight. */
    case IndividualPricingDhlFreight = 'individual_pricing_dhl_freight';
}
