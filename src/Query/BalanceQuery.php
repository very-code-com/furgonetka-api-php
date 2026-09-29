<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Query;

use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Enum\BillGroup;
use VeryCodeCom\FurgonetkaApi\Internal\Json\DateFormat;

/**
 * What to ask `GET /account/balance` for: how many operations, from which day to which, only money in
 * or out, only one group.
 *
 * Two things the sandbox showed and the specification does not say: the dates are days,
 * `YYYY-MM-DD`, and a date with a time (the specification's own example) is refused; and an `items`
 * above 1000 is not refused but read as 10, so it is refused here. Pages come from `page`, or, more
 * stably while operations are being added, from `lastId`.
 *
 *   $balance = $client->fetchBalance(new BalanceQuery(items: 50, dateStart: '2026-09-01', effect: BalanceEffect::Minus));
 *   $next = $client->fetchBalance(new BalanceQuery(items: 50, lastId: $balance->nextLastId()));
 */
final class BalanceQuery
{
    public readonly ?string $dateStart;
    public readonly ?string $dateEnd;

    /**
     * @param int|null                       $page      Page, from 1.
     * @param int|null                       $lastId    Only operations with an id below this one.
     * @param int|null                       $items     Operations per page, 1 to 1000; Furgonetka's default is 10.
     * @param BalanceEffect|null             $effect    Only money in (`Plus`) or only money out (`Minus`).
     * @param string|\DateTimeInterface|null $dateStart First day, `YYYY-MM-DD`.
     * @param string|\DateTimeInterface|null $dateEnd   Last day, `YYYY-MM-DD`, itself included.
     * @param BillGroup|null                 $group     Only this group of operations.
     *
     * @throws \InvalidArgumentException when a number is out of range or a date is not a day.
     */
    public function __construct(
        public readonly ?int $page = null,
        public readonly ?int $lastId = null,
        public readonly ?int $items = null,
        public readonly ?BalanceEffect $effect = null,
        string|\DateTimeInterface|null $dateStart = null,
        string|\DateTimeInterface|null $dateEnd = null,
        public readonly ?BillGroup $group = null,
    ) {
        if ($page !== null && $page < 1) {
            throw new \InvalidArgumentException('BalanceQuery: the page starts at 1.');
        }

        if ($items !== null && ($items < 1 || $items > 1000)) {
            throw new \InvalidArgumentException('BalanceQuery: items must be between 1 and 1000; Furgonetka would read a larger number as 10.');
        }

        $this->dateStart = self::day($dateStart, 'dateStart');
        $this->dateEnd = self::day($dateEnd, 'dateEnd');
    }

    /**
     * The query string parameters, without what was not set.
     *
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return array_filter([
            'page' => $this->page,
            'last_id' => $this->lastId,
            'items' => $this->items,
            'balance_effect' => $this->effect?->value,
            'date_start' => $this->dateStart,
            'date_end' => $this->dateEnd,
            'product' => $this->group?->value,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function day(string|\DateTimeInterface|null $value, string $field): ?string
    {
        $day = DateFormat::date($value);

        if ($day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            throw new \InvalidArgumentException("BalanceQuery: {$field} is a day, YYYY-MM-DD; Furgonetka refuses a date with a time.");
        }

        return $day;
    }
}
