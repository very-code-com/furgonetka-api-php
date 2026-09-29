<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The price of a shipment with one carrier contract (`services_prices[]` of a price calculation).
 *
 * **A carrier that cannot carry the shipment is not an HTTP error.** The calculation answers 200
 * and marks that carrier `available: false` with the reason in `errors` (dimensions, address).
 * Always check {@see self::$available} before using {@see self::$pricing}, or use
 * {@see PriceQuoteList::cheapest()}, which skips them.
 */
final class PriceQuote
{
    /**
     * @param int|null              $serviceId           The contract that was priced.
     * @param string|null           $carrierName         Carrier identifier as sent.
     * @param Carrier|null          $carrier             The same as an enum; null when it is not a member.
     * @param AgreementOwner|null   $agreementOwner      Whose contract; null when it is not a member.
     * @param string|null           $agreementOwnerValue Owner as sent.
     * @param bool                  $available           False when this carrier cannot carry the shipment.
     * @param list<ApiError>        $errors              Why it cannot, when it cannot.
     * @param Pricing|null          $pricing             The price; can be null or zero for an unavailable quote.
     * @param CourierDetails|null   $courierDetails      Carrier facts (free insurance, COD terms, delivery time).
     * @param float|null            $lowestPriceGross    Lowest gross price of the last 30 days.
     * @param bool|null             $calculationPendingInBackground True while a price is still being worked out.
     * @param string|null           $shipmentType        Kind of shipment, when Furgonetka says.
     * @param string|null           $deliveryType        Kind of delivery, when Furgonetka says.
     * @param list<AdditionalFee>   $additionalFees      Fees on top of the price.
     * @param array<string, mixed>  $raw                 The whole object.
     */
    public function __construct(
        public readonly ?int $serviceId = null,
        public readonly ?string $carrierName = null,
        public readonly ?Carrier $carrier = null,
        public readonly ?AgreementOwner $agreementOwner = null,
        public readonly ?string $agreementOwnerValue = null,
        public readonly bool $available = false,
        public readonly array $errors = [],
        public readonly ?Pricing $pricing = null,
        public readonly ?CourierDetails $courierDetails = null,
        public readonly ?float $lowestPriceGross = null,
        public readonly ?bool $calculationPendingInBackground = null,
        public readonly ?string $shipmentType = null,
        public readonly ?string $deliveryType = null,
        public readonly array $additionalFees = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $pricing = Value::object($data, 'pricing');
        $details = Value::object($data, 'courier_details');
        $lowest = Value::object($data, 'lowest_price');

        return new self(
            serviceId: Value::int($data, 'service_id'),
            carrierName: Value::string($data, 'service'),
            carrier: Value::enum($data, 'service', Carrier::class),
            agreementOwner: Value::enum($data, 'agreement_owner', AgreementOwner::class),
            agreementOwnerValue: Value::string($data, 'agreement_owner'),
            available: Value::bool($data, 'available') ?? false,
            errors: array_map(ApiError::fromArray(...), Value::objectList($data, 'errors')),
            pricing: $pricing !== null ? Pricing::fromArray($pricing) : null,
            courierDetails: $details !== null ? CourierDetails::fromArray($details) : null,
            lowestPriceGross: $lowest !== null ? Value::float($lowest, 'price_gross') : null,
            calculationPendingInBackground: Value::bool($data, 'calculation_pending_in_background'),
            shipmentType: Value::string($data, 'shipment_type'),
            deliveryType: Value::string($data, 'delivery_type'),
            additionalFees: array_map(AdditionalFee::fromArray(...), Value::objectList($data, 'additional_fees')),
            raw: $data,
        );
    }

    /**
     * The whole object as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
