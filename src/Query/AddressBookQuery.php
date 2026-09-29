<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Query;

use VeryCodeCom\FurgonetkaApi\Dto\AddressBookEntry;

/**
 * What to ask `GET /account/address-book` for: a page, a search phrase, some ids, or the default
 * sender alone.
 *
 *   $page = $client->listAddressBook(new AddressBookQuery(search: 'Kowalski'));
 *   $next = $client->listAddressBook(new AddressBookQuery(page: 2));
 */
final class AddressBookQuery
{
    /**
     * @param int|null                     $page              Page, from 1; 50 entries a page.
     * @param string|null                  $search            Search phrase.
     * @param list<int|AddressBookEntry>   $ids               Only these entries.
     * @param bool|null                    $onlyDefaultPickup Only the default sender.
     *
     * @throws \InvalidArgumentException when the page is below 1.
     */
    public function __construct(
        public readonly ?int $page = null,
        public readonly ?string $search = null,
        public readonly array $ids = [],
        public readonly ?bool $onlyDefaultPickup = null,
    ) {
        if ($page !== null && $page < 1) {
            throw new \InvalidArgumentException('AddressBookQuery: the page starts at 1.');
        }
    }

    /**
     * The query string parameters, without what was not set.
     *
     * @return array<string, scalar|array<mixed>>
     */
    public function toArray(): array
    {
        $ids = [];

        foreach ($this->ids as $id) {
            $number = $id instanceof AddressBookEntry ? $id->id : $id;

            if ($number !== null) {
                $ids[] = $number;
            }
        }

        return array_filter([
            'page' => $this->page,
            'search' => $this->search !== null && trim($this->search) !== '' ? $this->search : null,
            'ids' => $ids,
            'onlyDefaultPickup' => $this->onlyDefaultPickup,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
