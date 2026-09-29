<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Query;

use VeryCodeCom\FurgonetkaApi\Enum\ListType;

/**
 * What to ask `GET /packages` for.
 *
 * The list has no page numbers. It runs from the newest shipment to the oldest, and the next page
 * is "the `limit` shipments after this id": give `lastPackageId` the id of the last shipment you
 * got, or use {@see \VeryCodeCom\FurgonetkaApi\Dto\PackagePage::nextLastPackageId()}.
 *
 *   $page = $client->listPackages(new PackageListQuery(limit: 20, listType: ListType::Waiting));
 *   $next = $client->listPackages(new PackageListQuery(limit: 20, lastPackageId: $page->nextLastPackageId()));
 *
 * Only the `waiting` list reports the size of the whole result (`PackagePage::$count`).
 */
final class PackageListQuery
{
    /**
     * @param int|null            $limit         Shipments per page.
     * @param string|int|null     $lastPackageId Start after this shipment id.
     * @param ListType|null       $listType      Which list; Furgonetka's default when null.
     * @param string|null         $query         Free-text search (names, addresses, numbers).
     * @param PackageFilters|null $filters       Filters.
     */
    public function __construct(
        public readonly ?int $limit = null,
        public readonly string|int|null $lastPackageId = null,
        public readonly ?ListType $listType = null,
        public readonly ?string $query = null,
        public readonly ?PackageFilters $filters = null,
    ) {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('PackageListQuery: limit must be at least 1.');
        }
    }

    /**
     * The query string parameters, without what was not set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'limit' => $this->limit,
            'last_package_id' => $this->lastPackageId === null ? null : (string) $this->lastPackageId,
            'list_type' => $this->listType?->value,
            'query' => $this->query,
            'filters' => $this->filters?->toArray(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
