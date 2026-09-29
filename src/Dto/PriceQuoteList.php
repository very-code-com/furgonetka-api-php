<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The answer of a price calculation: one {@see PriceQuote} per carrier contract asked about.
 *
 * Carriers that cannot carry the shipment are in the list, marked unavailable, not left out or
 * turned into errors, so the helpers here skip them for you.
 *
 * @implements \IteratorAggregate<int, PriceQuote>
 */
final class PriceQuoteList implements \Countable, \IteratorAggregate
{
    /**
     * @param list<PriceQuote>     $quotes Every quote, available or not.
     * @param array<string, mixed> $raw    The whole answer.
     */
    public function __construct(
        public readonly array $quotes = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(PriceQuote::fromArray(...), Value::objectList($data, 'services_prices')), $data);
    }

    public function count(): int
    {
        return count($this->quotes);
    }

    /** @return \ArrayIterator<int, PriceQuote> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->quotes);
    }

    /**
     * The quotes of carriers that can carry the shipment.
     *
     * @return list<PriceQuote>
     */
    public function available(): array
    {
        return array_values(array_filter($this->quotes, static fn (PriceQuote $quote): bool => $quote->available));
    }

    /**
     * The cheapest available quote by gross price, or null when none is available or none has a price. Ties go to the first.
     */
    public function cheapest(): ?PriceQuote
    {
        $cheapest = null;

        foreach ($this->available() as $quote) {
            $price = $quote->pricing?->priceGross;

            if ($price !== null && ($cheapest === null || $price < ($cheapest->pricing->priceGross ?? INF))) {
                $cheapest = $quote;
            }
        }

        return $cheapest;
    }

    /**
     * The quotes of one carrier (it may have a contract of Furgonetka's and one of the customer's).
     *
     * @return list<PriceQuote>
     */
    public function forCarrier(Carrier|string $carrier): array
    {
        $name = $carrier instanceof Carrier ? $carrier->value : $carrier;

        return array_values(array_filter($this->quotes, static fn (PriceQuote $quote): bool => $quote->carrierName === $name));
    }

    /** The quote of one contract, or null when it was not asked about. */
    public function forServiceId(int $serviceId): ?PriceQuote
    {
        foreach ($this->quotes as $quote) {
            if ($quote->serviceId === $serviceId) {
                return $quote;
            }
        }

        return null;
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
