<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One contract with a carrier on the account (`GET /account/services`).
 *
 * `id` is what a shipment is created with (`ShipmentRequest::$serviceId`). It names a contract,
 * not a carrier: the same carrier can be on the account twice, on Furgonetka's contract and on the
 * customer's own, which is what {@see self::$owner} tells apart.
 */
final class Service
{
    /**
     * @param int|null             $id                  The contract's identifier.
     * @param string|null          $carrierName         Carrier identifier as sent (`service`).
     * @param Carrier|null         $carrier             The same as an enum; null when it is not a member (Furgonetka lists carriers no enum knows).
     * @param string|null          $name                Display name.
     * @param AgreementOwner|null  $owner               Whose contract; null when it is not a member.
     * @param string|null          $ownerValue          Owner as sent.
     * @param int|null             $pricelistId         The price list the contract uses.
     * @param bool|null            $isUpgradedPricelist Whether the account has a custom price list for this carrier.
     * @param bool|null            $codEnabled          Whether cash on delivery is available on this contract.
     * @param bool|null            $nationalOnly        Whether international shipments are not possible.
     * @param array<string, mixed> $raw                 The whole object.
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $carrierName = null,
        public readonly ?Carrier $carrier = null,
        public readonly ?string $name = null,
        public readonly ?AgreementOwner $owner = null,
        public readonly ?string $ownerValue = null,
        public readonly ?int $pricelistId = null,
        public readonly ?bool $isUpgradedPricelist = null,
        public readonly ?bool $codEnabled = null,
        public readonly ?bool $nationalOnly = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $configuration = Value::object($data, 'configuration') ?? [];
        $additional = Value::object($configuration, 'additional_services') ?? [];

        return new self(
            id: Value::int($data, 'id'),
            carrierName: Value::string($data, 'service'),
            carrier: Value::enum($data, 'service', Carrier::class),
            name: Value::string($data, 'name'),
            owner: Value::enum($data, 'owner', AgreementOwner::class),
            ownerValue: Value::string($data, 'owner'),
            pricelistId: Value::int($data, 'pricelist_id'),
            isUpgradedPricelist: Value::bool($data, 'is_upgraded_pricelist'),
            codEnabled: Value::bool($additional, 'cod_enabled'),
            nationalOnly: Value::bool($configuration, 'national_only'),
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
