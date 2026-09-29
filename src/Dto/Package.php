<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\PackageState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A shipment as Furgonetka reads it back (`PackageV2`): what `createPackage()`, `updatePackage()`
 * and `fetchPackage()` return and what a list holds.
 *
 * Only the fields a shipment's life depends on are typed here. The rest of the object (about sixty
 * more: `changes`, `documents`, `point_specific_details`, the duty data, the whole additional
 * services map, ...) is in {@see self::raw()}, so a field Furgonetka added yesterday is one array
 * access away. Deprecated fields are not modelled. The few that decide what a caller does next have
 * a method of their own: {@see self::signatureRequired()}, {@see self::documentsSigned()},
 * {@see self::returnDisposition()} and {@see self::readdressingDisposition()}.
 *
 * Things worth knowing:
 *  - `id` is a string here even where Furgonetka sends a number elsewhere.
 *  - `state` is an enum read with `tryFrom()`; a state added after this release reads as null and
 *    stays in `stateValue`. A deleted shipment is not found any more: Furgonetka answers 400
 *    ("You are not the owner of this package") for it, as for a stranger's.
 *  - The carrier waybill numbers are on the parcels (`PackageParcel::$packageNo`) and are null until the
 *    shipment is ordered.
 *  - Furgonetka normalises the addresses (it turned "Krakow" into "Kraków").
 *  - Timestamps without an offset (`cancellation_date` inside `cancel_details`, for instance) are
 *    Polish time; see {@see \VeryCodeCom\FurgonetkaApi\Internal\Json\DateFormat}.
 */
final class Package
{
    /**
     * @param string|null                    $id                  Furgonetka's identifier of the shipment (`package_id`).
     * @param string|null                    $groupId             Identifier of the group the shipment belongs to.
     * @param PackageAddress|null            $pickup              Where the courier collects; null for GLS means the carrier contract decides.
     * @param PackageAddress|null            $labelSender         The data printed on the label (`sender`), when other data was given.
     * @param PackageAddress|null            $receiver            Where the shipment is delivered, with the point code for a delivery to a pickup point.
     * @param Pricing|null                   $pricing             What the shipment costs.
     * @param string|null                    $userReferenceNumber Your document number, printed on the label.
     * @param string|null                    $carrierName         Carrier identifier as sent (`service`).
     * @param Carrier|null                   $carrier             The same as an enum; null when it is not a member.
     * @param string|null                    $transportService    The carrier that does the transport.
     * @param int|null                       $serviceId           The contract the shipment is on.
     * @param PackageState|null              $state               State; null when it is not a member.
     * @param string|null                    $stateValue          State as sent.
     * @param string|null                    $serviceContract     `provided` (Furgonetka's contract), `client` (the customer's own) or `foreign`.
     * @param bool|null                      $cancelAvailable     Whether the shipment can be cancelled.
     * @param bool|null                      $complaintAvailable  Whether a complaint can be filed.
     * @param bool|null                      $pickupAvailable     Whether a courier pickup can be ordered.
     * @param bool|null                      $repickup            Whether another pickup can be ordered (broker contracts only).
     * @param bool|null                      $codExpressAvailable Whether express COD can still be bought.
     * @param string|null                    $name                Name of the shipment.
     * @param string|null                    $pickupNumber        Pickup order number (DPD, InPost Kurier on broker contracts).
     * @param PickupProposal|null            $pickupDate          The booked pickup, when there is one.
     * @param \DateTimeImmutable|null        $datetimeAdd         When the shipment was created.
     * @param \DateTimeImmutable|null        $datetimeOrder       When it was ordered.
     * @param \DateTimeImmutable|null        $datetimeDelivery    When it was delivered.
     * @param string|null                    $deliveryTime        Delivery time in words.
     * @param string|null                    $editUrl             Page for editing the shipment in the panel.
     * @param string|null                    $documentsUrl        Page with the shipment's documents in the panel.
     * @param string|null                    $orderUuid           UUID of the order the shipment belongs to.
     * @param string|null                    $orderNumber         Number of that order.
     * @param list<PackageParcel>            $parcels             The parcels, each with its own type, state and waybill.
     * @param array<string, mixed>           $additionalServices  Every additional service as Furgonetka returns it, by API name.
     * @param array<string, mixed>           $raw                 The whole object as Furgonetka sent it.
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $groupId = null,
        public readonly ?PackageAddress $pickup = null,
        public readonly ?PackageAddress $labelSender = null,
        public readonly ?PackageAddress $receiver = null,
        public readonly ?Pricing $pricing = null,
        public readonly ?string $userReferenceNumber = null,
        public readonly ?string $carrierName = null,
        public readonly ?Carrier $carrier = null,
        public readonly ?string $transportService = null,
        public readonly ?int $serviceId = null,
        public readonly ?PackageState $state = null,
        public readonly ?string $stateValue = null,
        public readonly ?string $serviceContract = null,
        public readonly ?bool $cancelAvailable = null,
        public readonly ?bool $complaintAvailable = null,
        public readonly ?bool $pickupAvailable = null,
        public readonly ?bool $repickup = null,
        public readonly ?bool $codExpressAvailable = null,
        public readonly ?string $name = null,
        public readonly ?string $pickupNumber = null,
        public readonly ?PickupProposal $pickupDate = null,
        public readonly ?\DateTimeImmutable $datetimeAdd = null,
        public readonly ?\DateTimeImmutable $datetimeOrder = null,
        public readonly ?\DateTimeImmutable $datetimeDelivery = null,
        public readonly ?string $deliveryTime = null,
        public readonly ?string $editUrl = null,
        public readonly ?string $documentsUrl = null,
        public readonly ?string $orderUuid = null,
        public readonly ?string $orderNumber = null,
        public readonly array $parcels = [],
        public readonly array $additionalServices = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $address = static function (string $key) use ($data): ?PackageAddress {
            $object = Value::object($data, $key);

            return $object !== null ? PackageAddress::fromArray($object) : null;
        };
        $pricing = Value::object($data, 'pricing');
        $pickupDate = Value::object($data, 'pickup_date');

        return new self(
            id: Value::string($data, 'package_id'),
            groupId: Value::string($data, 'group_id'),
            pickup: $address('pickup'),
            labelSender: $address('sender'),
            receiver: $address('receiver'),
            pricing: $pricing !== null ? Pricing::fromArray($pricing) : null,
            userReferenceNumber: Value::string($data, 'user_reference_number'),
            carrierName: Value::string($data, 'service'),
            carrier: Value::enum($data, 'service', Carrier::class),
            transportService: Value::string($data, 'transport_service'),
            serviceId: Value::int($data, 'service_id'),
            state: Value::enum($data, 'state', PackageState::class),
            stateValue: Value::string($data, 'state'),
            serviceContract: Value::string($data, 'service_contract'),
            cancelAvailable: Value::bool($data, 'cancel_available'),
            complaintAvailable: Value::bool($data, 'complaint_available'),
            pickupAvailable: Value::bool($data, 'pickup_available'),
            repickup: Value::bool($data, 'repickup'),
            codExpressAvailable: Value::bool($data, 'cod_express_available'),
            name: Value::string($data, 'name'),
            pickupNumber: Value::string($data, 'pickup_number'),
            pickupDate: $pickupDate !== null ? PickupProposal::fromArray($pickupDate) : null,
            datetimeAdd: Value::dateTime($data, 'datetime_add'),
            datetimeOrder: Value::dateTime($data, 'datetime_order'),
            datetimeDelivery: Value::dateTime($data, 'datetime_delivery'),
            deliveryTime: Value::string($data, 'delivery_time'),
            editUrl: Value::string($data, 'edit_url'),
            documentsUrl: Value::string($data, 'documents_url'),
            orderUuid: Value::string($data, 'order_uuid'),
            orderNumber: Value::string($data, 'order_number'),
            parcels: array_map(PackageParcel::fromArray(...), Value::objectList($data, 'parcels')),
            additionalServices: Value::object($data, 'additional_services') ?? [],
            raw: $data,
        );
    }

    /** True when the shipment has been ordered. */
    public function isOrdered(): bool
    {
        return $this->state === PackageState::Ordered;
    }

    /**
     * The carrier waybill numbers of the parcels that have one, which is what a recipient tracks.
     *
     * @return list<string>
     */
    public function waybillNumbers(): array
    {
        $numbers = [];

        foreach ($this->parcels as $parcel) {
            if ($parcel->packageNo !== null) {
                $numbers[] = $parcel->packageNo;
            }
        }

        return $numbers;
    }

    /**
     * Whether the customs documents of this shipment still wait for the sender's signature
     * (`duty.signature_required`). While it is true an order is refused with a 400 `noSignature`: the
     * sender has to sign on Furgonetka's own page, the API has no call for a signature. Null for a
     * shipment without customs data, that is one that stays inside the EU.
     */
    public function signatureRequired(): ?bool
    {
        return Value::bool(Value::object($this->raw, 'duty') ?? [], 'signature_required');
    }

    /**
     * Whether the sender has signed the customs documents (`duty.documents_signed`). Changing the
     * shipment with `updatePackage()` drops the signature. Null for a shipment without customs data.
     */
    public function documentsSigned(): ?bool
    {
        return Value::bool(Value::object($this->raw, 'duty') ?? [], 'documents_signed');
    }

    /**
     * The return ordered for this shipment, or null when there is none. Never seen filled in: the
     * sandbox refused every return.
     */
    public function returnDisposition(): ?ReturnDisposition
    {
        $data = Value::object($this->raw, 'return_disposition');

        return $data !== null && $data !== [] ? ReturnDisposition::fromArray($data) : null;
    }

    /**
     * The readdressing ordered for this shipment, or null when there is none. Never seen filled in:
     * the sandbox refused every readdressing.
     */
    public function readdressingDisposition(): ?ReaddressingDisposition
    {
        $data = Value::object($this->raw, 'readdressing_disposition');

        return $data !== null && $data !== [] ? ReaddressingDisposition::fromArray($data) : null;
    }

    /**
     * The whole object as Furgonetka sent it, for a field this release does not model.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
