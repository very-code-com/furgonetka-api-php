<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\AddressBookQuery;

return Recipe::query(
    title: 'Account: searching the address book',
    shows: 'AddressBookQuery::search finds entries by any part of the name, company or address.',
    query: static function (FurgonetkaApiClient $client): array {
        $first = $client->listAddressBook()->entries[0] ?? throw new \RuntimeException('The address book is empty.');
        $term = (string) ($first->surname ?? $first->company ?? $first->name);
        $found = $client->listAddressBook(new AddressBookQuery(search: $term));

        return ['Searching for "' . $term . '" found ' . count($found) . ' of ' . $found->totalEntries . ' entries'];
    },
);
