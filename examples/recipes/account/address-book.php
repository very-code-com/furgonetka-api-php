<?php

declare(strict_types=1);

use VeryCodeCom\FurgonetkaApi\Examples\Recipe;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;

return Recipe::query(
    title: 'Account: the address book',
    shows: 'listAddressBook() pages of 50 entries. A list entry has full_name null; the person is in name and '
        . 'surname.',
    query: static function (FurgonetkaApiClient $client): array {
        $page = $client->listAddressBook();
        $lines = [count($page) . ' of ' . $page->totalEntries . ' entries:'];
        foreach ($page as $entry) {
            $lines[] = sprintf('%-8s %-24s %s', (string) $entry->id, trim(($entry->name ?? '') . ' ' . ($entry->surname ?? '')) ?: (string) $entry->company, (string) $entry->city);
        }

        return $lines;
    },
);
