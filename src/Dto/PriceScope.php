<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;

/**
 * Which carriers to price (`services` of a price calculation).
 *
 * Furgonetka wants either the carriers' types or the identifiers of your contracts. A type prices
 * every contract you have with that carrier (the same carrier can be on Furgonetka's contract and
 * on your own); an identifier prices exactly one contract.
 *
 *   PriceScope::carriers(Carrier::Dpd, Carrier::InPost)
 *   PriceScope::serviceIds(12014776, 12014781)
 *
 * A carrier can be given as a string as well, for one this release does not list yet.
 */
final class PriceScope
{
    /** @var list<string> */
    public readonly array $carriers;

    /**
     * @param list<Carrier|string> $carriers   Carrier types, as members or as their identifiers.
     * @param list<int>            $serviceIds Contract identifiers (`GET /account/services`).
     *
     * @throws \InvalidArgumentException when both are empty, or a value is malformed.
     */
    public function __construct(array $carriers = [], public readonly array $serviceIds = [])
    {
        $names = [];

        foreach ($carriers as $carrier) {
            $name = $carrier instanceof Carrier ? $carrier->value : trim($carrier);

            if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
                throw new \InvalidArgumentException('PriceScope: a carrier is an identifier such as "dpd", not "' . $name . '".');
            }

            $names[$name] = $name;
        }

        foreach ($serviceIds as $id) {
            if ($id < 1) {
                throw new \InvalidArgumentException('PriceScope: a service id is a positive integer.');
            }
        }

        if ($names === [] && $serviceIds === []) {
            throw new \InvalidArgumentException('PriceScope: give at least one carrier or one service id.');
        }

        $this->carriers = array_values($names);
    }

    /** Price these carrier types. */
    public static function carriers(Carrier|string ...$carriers): self
    {
        return new self(array_values($carriers));
    }

    /** Price these contracts. */
    public static function serviceIds(int ...$serviceIds): self
    {
        return new self([], array_values($serviceIds));
    }

    /** @return array<string, list<mixed>> */
    public function toArray(): array
    {
        return array_filter([
            'service' => $this->carriers,
            'service_id' => $this->serviceIds,
        ], static fn (array $list): bool => $list !== []);
    }
}
