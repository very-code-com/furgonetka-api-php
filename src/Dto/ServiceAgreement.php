<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** A contract of your own with a carrier, which the account has entered in the panel. */
final class ServiceAgreement
{
    /**
     * @param int|null     $agreementId Id of the agreement.
     * @param int|null     $serviceId   Id of the carrier's service; the `service_id` of a shipment.
     * @param string|null  $service     Carrier as sent.
     * @param Carrier|null $carrier     Carrier; null when it is not a member.
     * @param string|null  $name        The name the user gave it.
     */
    public function __construct(
        public readonly ?int $agreementId = null,
        public readonly ?int $serviceId = null,
        public readonly ?string $service = null,
        public readonly ?Carrier $carrier = null,
        public readonly ?string $name = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::int($data, 'agreement_id'),
            Value::int($data, 'service_id'),
            Value::string($data, 'service'),
            Value::enum($data, 'service', Carrier::class),
            Value::string($data, 'name'),
        );
    }
}
