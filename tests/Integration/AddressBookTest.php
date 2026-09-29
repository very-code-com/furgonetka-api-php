<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\AddressBookEntry;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookRequest;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\AddressBookQuery;

/**
 * The address book against the real sandbox. Every entry is made here, carries a random marker in its
 * company name, and is deleted in `tearDownAfterClass()`, so the account's own entries are only read.
 */
final class AddressBookTest extends SandboxTestCase
{
    private static ?FurgonetkaApiClient $client = null;

    /** @var list<int> Entries this class made and has not deleted yet. */
    private static array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$client !== null && self::$created !== []) {
            try {
                self::$client->deleteAddressBookEntries(self::$created);
            } catch (\Throwable) {
                // A test may have deleted some already; the account keeps nothing that matters.
                foreach (self::$created as $id) {
                    try {
                        self::$client->deleteAddressBookEntries([$id]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        self::$created = [];
        self::$client = null;
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    private static function marker(): string
    {
        return 'Integration ' . bin2hex(random_bytes(4));
    }

    private function make(?AddressBookRequest $request = null): AddressBookEntry
    {
        $entry = $this->client()->createAddressBookEntry($request ?? new AddressBookRequest(
            name: 'Jan Testowy',
            company: self::marker(),
            email: 'integration@example.com',
            street: 'Prosta 51',
            postcode: '00-838',
            city: 'Warszawa',
            countryCode: 'PL',
            phone: '500200300',
        ));

        self::assertNotNull($entry->id);
        self::$created[] = $entry->id;

        return $entry;
    }

    private function forget(int $id): void
    {
        self::$created = array_values(array_filter(self::$created, static fn (int $c): bool => $c !== $id));
    }

    public function testAnEntryIsCreatedNormalisedFetchedAndListed(): void
    {
        $entry = $this->make();

        self::assertSame('+48500200300', $entry->phone, 'Furgonetka adds the prefix.');
        self::assertSame('Jan Testowy', $entry->fullName);
        self::assertSame('00-838', $entry->postcode);
        self::assertNotNull($entry->uuid);

        $fetched = $this->client()->fetchAddressBookEntry($entry);
        self::assertSame($entry->id, $fetched->id);
        self::assertSame($entry->company, $fetched->company);

        $found = $this->client()->listAddressBook(new AddressBookQuery(search: (string) $entry->company));
        self::assertNotNull($found->find((int) $entry->id), 'A search by the marker finds the entry.');

        $byId = $this->client()->listAddressBook(new AddressBookQuery(ids: [$entry]));
        self::assertCount(1, $byId);
    }

    public function testAnEntryIsChangedThroughItsOwnRequestAndKeepsTheRest(): void
    {
        $entry = $this->make();

        $this->client()->replaceAddressBookEntry($entry, $entry->toRequest()->with(['city' => 'Krakow', 'postcode' => '31-021']));

        $changed = $this->client()->fetchAddressBookEntry($entry);
        self::assertSame('Kraków', $changed->city, 'Furgonetka fixes the accents.');
        self::assertSame('31-021', $changed->postcode);
        self::assertSame($entry->fullName, $changed->fullName);
        self::assertSame($entry->email, $changed->email);
        self::assertSame($entry->phone, $changed->phone);
        self::assertSame($entry->company, $changed->company);
        self::assertSame($entry->uuid, $changed->uuid, 'The public UUID survives, because the request carries it.');
    }

    public function testAReplacementIsTotalWhateverTheRequestLacksIsWiped(): void
    {
        $entry = $this->make();

        $this->client()->replaceAddressBookEntry($entry, new AddressBookRequest(company: (string) $entry->company));

        $wiped = $this->client()->fetchAddressBookEntry($entry);
        self::assertSame($entry->company, $wiped->company);
        self::assertNull($wiped->fullName);
        self::assertNull($wiped->email);
        self::assertNull($wiped->street);
        self::assertNull($wiped->phone);
    }

    public function testAnEntryCanBeMadePublicAndThenHasASendToMeLink(): void
    {
        $entry = $this->make();
        self::assertNull($entry->sendToMeLink);

        $this->client()->replaceAddressBookEntry($entry, $entry->toRequest()->with(['isPublic' => true]));

        $public = $this->client()->fetchAddressBookEntry($entry);
        self::assertTrue($public->isPublic);
        self::assertNotNull($public->sendToMeLink);
        self::assertStringContainsString((string) $entry->uuid, (string) $public->sendToMeLink);
    }

    public function testAPointEntryCarriesThePoint(): void
    {
        $entry = $this->make(new AddressBookRequest(company: self::marker(), email: 'integration@example.com', phone: '500200300', point: 'WAW099', service: 'inpost'));

        self::assertSame('inpost', $entry->serviceValue);
        self::assertSame('WAW099', $entry->point);
        self::assertSame('WAW099', $entry->pointData?->code);
        self::assertNotNull($entry->pointLabel);
    }

    public function testAnIbanIsAcceptedAndNeverSentBack(): void
    {
        $entry = $this->make(new AddressBookRequest(name: 'Jan Testowy', company: self::marker(), iban: 'PL61109010140000071219812874', ibanName: 'Jan Testowy'));

        self::assertArrayNotHasKey('iban', $entry->raw());
        self::assertArrayNotHasKey('iban_name', $entry->raw());
        self::assertArrayNotHasKey('iban', $this->client()->fetchAddressBookEntry($entry)->raw());
    }

    public function testTheValidationAgreesWithTheSave(): void
    {
        self::assertSame([], $this->client()->validateAddressBookEntry(new AddressBookRequest(name: 'Jan Testowy', email: 'integration@example.com')));

        $errors = $this->client()->validateAddressBookEntry(new AddressBookRequest(name: 'Jan Testowy', email: 'not-an-email'));
        self::assertSame(['/email'], array_map(static fn ($e): ?string => $e->path, $errors));
        self::assertSame('invalid', $errors[0]->code);

        try {
            $this->client()->createAddressBookEntry(new AddressBookRequest(name: 'Jan Testowy', email: 'not-an-email'));
            self::fail('The save must refuse what the validation refused.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/email'], $e->getFieldPaths());
        }
    }

    public function testADeletedEntryIsGoneAndDeletingItAgainIsRefused(): void
    {
        $entry = $this->make();
        $id = (int) $entry->id;

        $this->client()->deleteAddressBookEntries([$id]);
        $this->forget($id);

        try {
            $this->client()->fetchAddressBookEntry($id);
            self::fail('The entry is deleted.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notExists'));
            self::assertSame(['/id'], $e->getFieldPaths());
        }

        try {
            $this->client()->deleteAddressBookEntries([$id]);
            self::fail('There is nothing to delete.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/entries/0/id'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('notExists'));
        }

        try {
            $this->client()->replaceAddressBookEntry($id, new AddressBookRequest(name: 'Jan'));
            self::fail('There is nothing to replace.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notExists'));
        }
    }

    public function testOneUnknownIdFailsTheWholeDeletion(): void
    {
        $entry = $this->make();

        try {
            $this->client()->deleteAddressBookEntries([(int) $entry->id, 1]);
            self::fail('An unknown id must fail the call.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/entries/1/id'], $e->getFieldPaths());
        }

        self::assertSame($entry->id, $this->client()->fetchAddressBookEntry($entry)->id, 'The known entry was not deleted either.');
    }

    public function testTheDefaultSenderFilterReturnsOnlyDefaultSenders(): void
    {
        $page = $this->client()->listAddressBook(new AddressBookQuery(onlyDefaultPickup: true));

        foreach ($page as $entry) {
            self::assertTrue($entry->defaultPickup, (string) $entry->id);
        }
    }
}
