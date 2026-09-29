<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A page of the account's operations and the balance (`GET /account/balance`).
 *
 * `billsCount` and `billsSum` cover every operation that matches the filters, not only this page. The
 * balance is the account's current one whatever the filters say. The pages go from the newest
 * operation down; the next page starts after {@see self::nextLastId()}.
 *
 * @implements \IteratorAggregate<int, Bill>
 */
final class Balance implements \Countable, \IteratorAggregate
{
    /**
     * @param list<Bill>           $bills                          The operations of this page, newest first.
     * @param int|null             $billsCount                     Number of operations that match the filters.
     * @param float|null           $billsSum                       Sum of those operations, PLN.
     * @param float|null           $balance                        The current balance, PLN.
     * @param string|null          $currency                       Currency; not in the specification, "PLN" on the sandbox.
     * @param array<string, mixed>|null $creditUsage               How much credit is used, as sent; null without a credit line. Its fields are not described.
     * @param list<string>         $filterMonths                   Months that have operations, for instance "2026-09".
     * @param float|null           $balanceWithoutHaulerCommission The balance without the carrier's commission, PLN.
     * @param array<string, mixed> $raw                            The whole answer.
     */
    public function __construct(
        public readonly array $bills = [],
        public readonly ?int $billsCount = null,
        public readonly ?float $billsSum = null,
        public readonly ?float $balance = null,
        public readonly ?string $currency = null,
        public readonly ?array $creditUsage = null,
        public readonly array $filterMonths = [],
        public readonly ?float $balanceWithoutHaulerCommission = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(Bill::fromArray(...), Value::objectList($data, 'bills')),
            Value::int($data, 'bills_count'),
            Value::float($data, 'bills_sum'),
            Value::float($data, 'balance'),
            Value::string($data, 'currency'),
            Value::object($data, 'credit_usage'),
            Value::stringList($data, 'filter_months'),
            Value::float($data, 'balance_without_hauler_commission'),
            $data,
        );
    }

    /** Number of operations on this page. */
    public function count(): int
    {
        return count($this->bills);
    }

    /** @return \ArrayIterator<int, Bill> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->bills);
    }

    /** The id to give as `lastId` for the next page; null when this page is empty. */
    public function nextLastId(): ?int
    {
        return $this->bills !== [] ? $this->bills[array_key_last($this->bills)]->id : null;
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
