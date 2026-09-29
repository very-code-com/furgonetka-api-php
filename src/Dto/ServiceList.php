<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The carrier contracts of the account (`GET /account/services`), with lookups.
 *
 *   $dpd = $client->listServices()->first(Carrier::Dpd);   // the DPD contract; $dpd->id goes into the shipment
 *
 * @implements \IteratorAggregate<int, Service>
 */
final class ServiceList implements \Countable, \IteratorAggregate
{
    /**
     * @param list<Service>        $services Every contract.
     * @param array<string, mixed> $raw      The whole answer.
     */
    public function __construct(
        public readonly array $services = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(Service::fromArray(...), Value::objectList($data, 'services')), $data);
    }

    public function count(): int
    {
        return count($this->services);
    }

    /** @return \ArrayIterator<int, Service> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->services);
    }

    /** The contract with this identifier, or null. */
    public function find(int $id): ?Service
    {
        foreach ($this->services as $service) {
            if ($service->id === $id) {
                return $service;
            }
        }

        return null;
    }

    /**
     * The first contract with a carrier, optionally on a given owner's contract. A carrier can be
     * a string, for one this release does not list yet.
     */
    public function first(Carrier|string $carrier, ?AgreementOwner $owner = null): ?Service
    {
        return $this->forCarrier($carrier, $owner)[0] ?? null;
    }

    /**
     * Every contract with a carrier.
     *
     * @return list<Service>
     */
    public function forCarrier(Carrier|string $carrier, ?AgreementOwner $owner = null): array
    {
        $name = $carrier instanceof Carrier ? $carrier->value : $carrier;

        return array_values(array_filter(
            $this->services,
            static fn (Service $service): bool => $service->carrierName === $name && ($owner === null || $service->owner === $owner),
        ));
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
