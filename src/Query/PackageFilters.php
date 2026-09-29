<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Query;

use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\DateFormat;

/**
 * The filters of a shipment list (`filters` of `GET /packages`).
 *
 * Every filter is a named parameter, and that is the point of the class: Furgonetka silently
 * ignores a filter it does not know, so a misspelt key (`service` for `services`) returns an
 * unfiltered list and nobody notices. Filters whose values are open-ended (carriers, states) take
 * the enum or its string, for a value this release does not list yet.
 *
 * Verified on the sandbox: `services`, `packages_ids` and `relations` filter, and arrays go on the
 * wire as `filters[services][0]=dpd`, which Furgonetka reads.
 */
final class PackageFilters
{
    /**
     * @param list<Carrier|string>        $services        Only these carriers.
     * @param string|null                 $interval        `all`, `today`, `yesterday`, `month-ago` or `two-months-ago`.
     * @param string|\DateTimeInterface|null $dateFrom     First day, `YYYY-MM-DD`; with `$dateTo`, replaces `$interval`.
     * @param string|\DateTimeInterface|null $dateTo       Last day; must be after `$dateFrom`.
     * @param list<ParcelType|string>     $packageTypes    Only these kinds: `package`, `dox`, `pallet`, `letterprint`.
     * @param list<string>                $relations       `domestic` and/or `international`.
     * @param list<TrackingState|string>  $statuses        Only these tracking states.
     * @param bool|null                   $withCod         True for shipments with cash on delivery only.
     * @param list<string>                $codStatuses     COD states (`waiting`, `sent`); needs `$withCod`.
     * @param list<string>                $orderTypes      `with-pickup` and/or `without-pickup`.
     * @param list<int|string>            $packagesIds     Only these shipments.
     * @param list<int|string>            $groupsIds       Only these groups.
     * @param string|null                 $groups          `groups-only` or `packages-only`.
     * @param list<string>                $serviceContract `provided` (Furgonetka's contract) and/or `client` (the customer's own).
     */
    public function __construct(
        public readonly array $services = [],
        public readonly ?string $interval = null,
        public readonly string|\DateTimeInterface|null $dateFrom = null,
        public readonly string|\DateTimeInterface|null $dateTo = null,
        public readonly array $packageTypes = [],
        public readonly array $relations = [],
        public readonly array $statuses = [],
        public readonly ?bool $withCod = null,
        public readonly array $codStatuses = [],
        public readonly array $orderTypes = [],
        public readonly array $packagesIds = [],
        public readonly array $groupsIds = [],
        public readonly ?string $groups = null,
        public readonly array $serviceContract = [],
    ) {
    }

    /**
     * The filters as query parameters, without what was not set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $enumValues = static fn (array $items): array => array_map(
            static fn (mixed $item): string => $item instanceof \BackedEnum ? (string) $item->value : (string) $item,
            $items,
        );
        $from = DateFormat::date($this->dateFrom);
        $to = DateFormat::date($this->dateTo);

        return array_filter([
            'services' => $enumValues($this->services),
            'interval' => $this->interval,
            'date' => $from !== null || $to !== null ? array_filter(['from' => $from, 'to' => $to]) : null,
            'package_types' => $enumValues($this->packageTypes),
            'relations' => $this->relations,
            'statuses' => $enumValues($this->statuses),
            'with_cod' => $this->withCod,
            'cod_statuses' => $this->codStatuses,
            'order_types' => $this->orderTypes,
            'packages_ids' => array_map(static fn (int|string $id): string => (string) $id, $this->packagesIds),
            'groups_ids' => array_map(static fn (int|string $id): string => (string) $id, $this->groupsIds),
            'groups' => $this->groups,
            'service_contract' => $this->serviceContract,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
