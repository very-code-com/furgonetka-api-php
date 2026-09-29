<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One page of the address book (`GET /account/address-book`, 50 entries per page).
 *
 * @implements \IteratorAggregate<int, AddressBookEntry>
 */
final class AddressBookPage implements \Countable, \IteratorAggregate
{
    /**
     * @param list<AddressBookEntry> $entries
     * @param int|null               $page
     * @param int|null               $totalPages   Number of pages.
     * @param int|null               $totalEntries Number of entries in all.
     * @param array<string, mixed>   $raw          The whole answer.
     */
    public function __construct(
        public readonly array $entries = [],
        public readonly ?int $page = null,
        public readonly ?int $totalPages = null,
        public readonly ?int $totalEntries = null,
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(AddressBookEntry::fromArray(...), Value::objectList($data, 'address_book_entries')),
            Value::int($data, 'page'),
            Value::int($data, 'total_pages'),
            Value::int($data, 'total_entries'),
            $data,
        );
    }

    /** Number of entries on this page. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** @return \ArrayIterator<int, AddressBookEntry> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }

    /** True when there is a page after this one. */
    public function hasNextPage(): bool
    {
        return $this->page !== null && $this->totalPages !== null && $this->page < $this->totalPages;
    }

    /** The entry with this id, or null. */
    public function find(int $id): ?AddressBookEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
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
