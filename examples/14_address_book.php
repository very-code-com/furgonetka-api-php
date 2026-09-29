<?php

declare(strict_types=1);

/**
 * The address book: list, add, change, delete and validate entries.
 *
 *   php examples/14_address_book.php
 *
 * It adds one entry marked "Example", changes it, and deletes it again; the entries you had are only read.
 *
 * What it shows:
 *   - listAddressBook() pages of 50, with a search phrase or ids; AddressBookPage::hasNextPage() says when
 *     there is another;
 *   - createAddressBookEntry() answers with the entry as Furgonetka stored it, normalised: the phone
 *     "500200300" comes back as "+48500200300" and "Krakow" as "Krakow" with its accents;
 *   - THE REPLACEMENT IS TOTAL. replaceAddressBookEntry() stores exactly what you send and empties the rest:
 *     sending only a new company name wiped the name, e-mail, address and phone on the sandbox. So change an
 *     entry from itself: `$entry->toRequest()->with(['city' => 'Krakow'])`. The IBAN and its owner's name are
 *     never sent back by Furgonetka, so set them again if the entry had them;
 *   - validateAddressBookEntry() asks whether an entry would be accepted, without storing it;
 *   - deleting is atomic: one unknown id refuses the whole call.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\AddressBookRequest;
use VeryCodeCom\FurgonetkaApi\Query\AddressBookQuery;

exampleRun(static function (): void {
    $client = exampleClient();

    exampleHeading('Your entries');
    $page = $client->listAddressBook();
    foreach ($page as $entry) {
        // A list entry has `full_name` null; the name is in `name` and `surname`, and a company entry has neither.
        $label = trim($entry->fullName ?? trim(($entry->name ?? '') . ' ' . ($entry->surname ?? ''))) ?: (string) $entry->company;
        printf("%-8s %-24s %s %s%s\n", (string) $entry->id, $label, (string) $entry->street, (string) $entry->city, $entry->defaultPickup === true ? '  [default sender]' : '');
    }
    echo count($page) . ' of ' . (string) $page->totalEntries . ' entries' . ($page->hasNextPage() ? ', more on the next page' : '') . PHP_EOL;

    exampleHeading('Validate before saving');
    $request = new AddressBookRequest(name: 'Jan Przyklad', company: 'Example', email: 'jan@example.com', street: 'Prosta 51', postcode: '00-838', city: 'Warszawa', countryCode: 'PL', phone: '500200300');
    $bad = $request->with(['email' => 'not-an-email']);
    $errors = $client->validateAddressBookEntry($bad);
    echo $errors === [] ? "Accepted.\n" : 'Refused: ' . implode('; ', array_map('strval', $errors)) . PHP_EOL;

    exampleHeading('Add, change, find, delete');
    $entry = $client->createAddressBookEntry($request);
    echo "Added {$entry->id}: phone {$entry->phone}, city {$entry->city}\n";

    try {
        $client->replaceAddressBookEntry($entry, $entry->toRequest()->with(['city' => 'Krakow', 'postcode' => '31-021']));
        $changed = $client->fetchAddressBookEntry($entry);
        echo "Changed: {$changed->city} {$changed->postcode}; the name is still {$changed->fullName}\n";

        $found = $client->listAddressBook(new AddressBookQuery(search: 'Example'));
        echo 'A search for "Example" finds ' . count($found) . " entr" . (count($found) === 1 ? 'y' : 'ies') . ".\n";
    } finally {
        $client->deleteAddressBookEntries([$entry]);
        echo "Deleted {$entry->id}.\n";
    }
});
