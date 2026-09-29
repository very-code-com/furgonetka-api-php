<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A shipment you follow without having sent it through Furgonetka: what `addToTracking()` and
 * `renameTrackedPackage()` answer.
 *
 * Adding answers with the id and the carrier Furgonetka recognised, renaming with the id and the
 * new name, so each answer leaves the other field null. The id is the one to give `fetchTracking()`.
 */
final class TrackedPackage
{
    /**
     * @param string|null          $id          Furgonetka's identifier of the tracked shipment.
     * @param string|null          $carrierName Carrier identifier as sent.
     * @param Carrier|null         $carrier     Carrier; null when it is not a member.
     * @param string|null          $name
     * @param array<string, mixed> $raw         The whole answer.
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $carrierName = null,
        public readonly ?Carrier $carrier = null,
        public readonly ?string $name = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Value::string($data, 'package_id'),
            carrierName: Value::string($data, 'service'),
            carrier: Value::enum($data, 'service', Carrier::class),
            name: Value::string($data, 'name'),
            raw: $data,
        );
    }

    /**
     * The whole answer as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
