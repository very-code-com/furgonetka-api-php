<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookEntry;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookPage;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookRequest;
use VeryCodeCom\FurgonetkaApi\Enum\InvoiceDataType;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Query\AddressBookQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The address book: entries and pages are real bodies from the sandbox (phones and the like scrubbed),
 * the request is checked against what the sandbox accepted and refused.
 */
final class AddressBookTest extends TestCase
{
    /** Exactly 15 characters: a trace cuts string arguments to 15, so a shorter secret could never show up. */
    private const IBAN = 'PL1234567890123';

    // -- The entry ---------------------------------------------------------------------------------

    public function testAnEntryReadsEmptyStringsAsNull(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry'));

        self::assertSame(380861, $entry->id);
        self::assertNull($entry->service, 'The sandbox sends "" for an entry that is not a point.');
        self::assertNull($entry->serviceValue);
        self::assertSame('Phase Seven', $entry->fullName);
        self::assertSame('Phase', $entry->name);
        self::assertSame('Seven', $entry->surname);
        self::assertSame('Phase 7 test', $entry->company);
        self::assertSame('phase7@example.com', $entry->email);
        self::assertSame('Prosta 51', $entry->street);
        self::assertSame('00-838', $entry->postcode);
        self::assertSame('Warszawa', $entry->city);
        self::assertSame('PL', $entry->countryCode);
        self::assertNull($entry->county);
        self::assertNull($entry->point);
        self::assertNull($entry->pointData);
        self::assertNull($entry->nip);
        self::assertNull($entry->sendToMeLink);
        self::assertNull($entry->buildingNumber);
        self::assertNull($entry->flatNumber);
        self::assertSame('909f39ef-2ee2-4b71-8679-84669dc46553', $entry->uuid);
    }

    public function testThePhoneComesAsTextAndSplit(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry'));

        self::assertSame('+48500200300', $entry->phone, 'The sandbox added the prefix to the "500200300" it was sent.');
        self::assertSame('48', $entry->phoneNumber?->prefix);
        self::assertSame('500200300', $entry->phoneNumber->number);
        self::assertSame('PL', $entry->phoneNumber->countryCode);
    }

    public function testTheFlagsAndTheInvoiceType(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry'));

        self::assertFalse($entry->defaultPickup);
        self::assertFalse($entry->defaultSender);
        self::assertFalse($entry->defaultMyAddress);
        self::assertFalse($entry->invoiceData);
        self::assertFalse($entry->isPublic);
        self::assertSame(InvoiceDataType::Personal, $entry->invoiceDataType);
        self::assertSame('personal', $entry->invoiceDataTypeValue);
    }

    public function testAPublicEntryHasItsSendToMeLink(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry-updated'));

        self::assertTrue($entry->isPublic);
        self::assertSame('https://sandbox.furgonetka.pl/do/909f39ef-2ee2-4b71-8679-84669dc46553', $entry->sendToMeLink);
        self::assertSame('Kraków', $entry->city, 'Furgonetka fixed the accents of the "Krakow" it was sent.');
    }

    public function testAPointEntryCarriesThePointInFull(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry-point'));

        self::assertSame(PointService::Inpost, $entry->service);
        self::assertSame('inpost', $entry->serviceValue);
        self::assertSame('WAW099', $entry->point);
        self::assertSame('WAW099 - Towarowa 20, 00-839 Warszawa', $entry->pointLabel);
        self::assertSame('paczkomat', $entry->pointType);
        self::assertSame('WAW099', $entry->pointData?->code);
        self::assertSame('Towarowa 20', $entry->pointData->address?->street);
    }

    public function testAnEntryThatWasReplacedWithAPartialRequestIsMostlyEmpty(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry-partial'));

        self::assertSame('Phase 7 renamed', $entry->company);
        self::assertNull($entry->name);
        self::assertNull($entry->email);
        self::assertNull($entry->street);
        self::assertNull($entry->phone);
        self::assertNull($entry->phoneNumber?->number, 'The split phone is there with nulls in it.');
        self::assertNull($entry->fullName);
    }

    public function testAnUnknownCarrierOrInvoiceTypeKeepsTheRawValue(): void
    {
        $entry = AddressBookEntry::fromArray(['id' => 1, 'service' => 'newpost', 'invoice_data_type' => 'foundation']);

        self::assertNull($entry->service);
        self::assertSame('newpost', $entry->serviceValue);
        self::assertNull($entry->invoiceDataType);
        self::assertSame('foundation', $entry->invoiceDataTypeValue);
    }

    public function testTheWholeObjectIsKept(): void
    {
        $data = Fixtures::json('address-book/entry');

        self::assertSame($data, AddressBookEntry::fromArray($data)->raw());
    }

    // -- The page ------------------------------------------------------------------------------------

    public function testAPageOfEntries(): void
    {
        $page = AddressBookPage::fromArray(Fixtures::json('address-book/list'));

        self::assertCount(3, $page);
        self::assertSame(1, $page->page);
        self::assertSame(1, $page->totalPages);
        self::assertSame(3, $page->totalEntries);
        self::assertFalse($page->hasNextPage());
        self::assertSame([380708, 380710, 380709], array_map(static fn (AddressBookEntry $e): ?int => $e->id, iterator_to_array($page)));
        self::assertNull($page->entries[0]->fullName, 'A list entry has a null full name where a single one has "".');
        self::assertTrue($page->entries[0]->defaultPickup);
    }

    public function testAPageKeepsTheWholeBody(): void
    {
        $data = Fixtures::json('address-book/list');

        self::assertSame($data, AddressBookPage::fromArray($data)->raw());
    }

    public function testAPageFindsAnEntryById(): void
    {
        $page = AddressBookPage::fromArray(Fixtures::json('address-book/list'));

        self::assertSame('Maria', $page->find(380710)?->name);
        self::assertNull($page->find(1));
    }

    public function testAnotherPageIsAnnouncedByThePageCount(): void
    {
        $page = AddressBookPage::fromArray(['address_book_entries' => [], 'page' => 1, 'total_pages' => 2, 'total_entries' => 51]);

        self::assertTrue($page->hasNextPage());
        self::assertFalse(AddressBookPage::fromArray(['address_book_entries' => [], 'page' => 2, 'total_pages' => 2])->hasNextPage());
        self::assertFalse(AddressBookPage::fromArray([])->hasNextPage(), 'No paging fields, no next page.');
        self::assertCount(0, AddressBookPage::fromArray([]));
    }

    // -- The request -------------------------------------------------------------------------------

    public function testTheRequestSendsWhatIsSetAndOmitsTheRest(): void
    {
        $request = new AddressBookRequest(name: 'Phase Seven', company: 'Phase 7 test', email: 'phase7@example.com', street: 'Prosta 51', postcode: '00-838', city: 'Warszawa', countryCode: 'PL', phone: '500200300', defaultPickup: false);

        self::assertSame(
            ['name' => 'Phase Seven', 'company' => 'Phase 7 test', 'email' => 'phase7@example.com', 'street' => 'Prosta 51', 'postcode' => '00-838', 'city' => 'Warszawa', 'country_code' => 'PL', 'phone' => '500200300', 'default_pickup' => false],
            $request->toArray(),
            'A false flag is a value, only a null is left out.',
        );
    }

    public function testEveryFieldHasItsWireName(): void
    {
        $request = new AddressBookRequest('N', 'C', 'e@example.com', 'S', 'B', 'F', '00-001', 'City', 'UA', 'County', '1234567890', self::IBAN, 'Owner', '500200300', 'WAW099', 'inpost', true, true, true, true, '909f39ef-2ee2-4b71-8679-84669dc46553');

        self::assertSame(
            ['name' => 'N', 'company' => 'C', 'email' => 'e@example.com', 'street' => 'S', 'building_number' => 'B', 'flat_number' => 'F', 'postcode' => '00-001', 'city' => 'City', 'country_code' => 'UA', 'county' => 'County', 'nip' => '1234567890', 'iban' => self::IBAN, 'iban_name' => 'Owner', 'phone' => '500200300', 'point' => 'WAW099', 'service' => 'inpost', 'default_pickup' => true, 'default_my_address' => true, 'default_sender' => true, 'is_public' => true, 'uuid' => '909f39ef-2ee2-4b71-8679-84669dc46553'],
            $request->toArray(),
        );
    }

    public function testANameOrACompanyIsRequiredAndNothingElse(): void
    {
        self::assertSame(['name' => 'Jan'], (new AddressBookRequest(name: 'Jan'))->toArray());
        self::assertSame(['company' => 'Firma'], (new AddressBookRequest(company: 'Firma'))->toArray());

        foreach ([[], ['name' => '  '], ['company' => ''], ['name' => ' ', 'company' => ' ']] as $args) {
            try {
                new AddressBookRequest(...$args);
                self::fail('An entry without a name and a company must be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('name or a company', $e->getMessage());
            }
        }
    }

    public function testWithChangesOneFieldAndKeepsTheRest(): void
    {
        $request = new AddressBookRequest(name: 'Jan', city: 'Warszawa', phone: '500200300');

        $changed = $request->with(['city' => 'Krakow']);

        self::assertSame(['name' => 'Jan', 'city' => 'Krakow', 'phone' => '500200300'], $changed->toArray());
        self::assertSame('Warszawa', $request->city, 'The original is not touched.');
    }

    public function testWithNullClearsAField(): void
    {
        $request = (new AddressBookRequest(name: 'Jan', phone: '500200300'))->with(['phone' => null]);

        self::assertSame(['name' => 'Jan'], $request->toArray());
    }

    public function testWithRefusesAFieldThatIsNotInTheRequestAndNamesTheFields(): void
    {
        try {
            (new AddressBookRequest(name: 'Jan'))->with(['town' => 'Krakow']);
            self::fail('An unknown field must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"town"', $e->getMessage());
            self::assertStringContainsString('countryCode', $e->getMessage());
        }
    }

    public function testWithStillRequiresANameOrACompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AddressBookRequest(name: 'Jan'))->with(['name' => null]);
    }

    // -- From an entry back to a request ---------------------------------------------------------------

    public function testAnEntryTurnsIntoTheRequestThatWouldRecreateIt(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry-updated'));

        $request = $entry->toRequest();

        self::assertSame(
            ['name' => 'Phase Seven', 'company' => 'Phase 7 test', 'email' => 'phase7@example.com', 'street' => 'Prosta 51', 'postcode' => '31-021', 'city' => 'Kraków', 'country_code' => 'PL', 'phone' => '+48500200300', 'default_pickup' => false, 'default_my_address' => false, 'default_sender' => false, 'is_public' => true, 'uuid' => '909f39ef-2ee2-4b71-8679-84669dc46553'],
            $request->toArray(),
            'The empty strings are not sent back, the flags and the public UUID are.',
        );
    }

    public function testTheNameIsTheFullNameOrElseTheFirstAndLastName(): void
    {
        self::assertSame('Jan Maria Kowalski', (new AddressBookEntry(fullName: 'Jan Maria Kowalski', name: 'Jan', surname: 'Kowalski'))->toRequest()->name);
        self::assertSame('Jan Kowalski', (new AddressBookEntry(name: 'Jan', surname: 'Kowalski'))->toRequest()->name);
        self::assertSame('Jan', (new AddressBookEntry(name: 'Jan'))->toRequest()->name);
        self::assertNull((new AddressBookEntry(company: 'Firma'))->toRequest()->name, 'No name at all is null, not an empty text.');
    }

    public function testAPointEntryKeepsItsPointAndCarrier(): void
    {
        $request = AddressBookEntry::fromArray(Fixtures::json('address-book/entry-point'))->toRequest();

        self::assertSame('WAW099', $request->point);
        self::assertSame('inpost', $request->service);
        self::assertSame('Phase 7 point', $request->company);
    }

    public function testAnEntryWithNeitherNameNorCompanyCannotBecomeARequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AddressBookEntry(id: 1, city: 'Warszawa'))->toRequest();
    }

    public function testTheIbanIsNotInTheEntryAndSoNotInTheRequestMadeFromIt(): void
    {
        $request = AddressBookEntry::fromArray(Fixtures::json('address-book/entry'))->toRequest();

        self::assertNull($request->iban);
        self::assertNull($request->ibanName);
        self::assertArrayNotHasKey('iban', $request->toArray());
    }

    // -- The IBAN is a secret ------------------------------------------------------------------------

    public function testTheIbanNeverShowsInADump(): void
    {
        self::assertSame(15, strlen(self::IBAN));
        $request = new AddressBookRequest(name: 'Jan', iban: self::IBAN);

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::IBAN, $dump);
        self::assertStringNotContainsString(self::IBAN, print_r($request, true));
        self::assertStringContainsString('[redacted]', $dump);
        self::assertSame(self::IBAN, $request->toArray()['iban'], 'The request itself still carries it.');
    }

    public function testACopyMadeWithWithKeepsTheIbanHiddenToo(): void
    {
        $copy = (new AddressBookRequest(name: 'Jan', iban: self::IBAN))->with(['city' => 'Krakow']);

        self::assertStringNotContainsString(self::IBAN, print_r($copy, true));
        self::assertSame(self::IBAN, $copy->iban);
    }

    public function testTheConstructorParameterIsMarkedSensitive(): void
    {
        $parameter = (new \ReflectionClass(AddressBookRequest::class))->getConstructor()?->getParameters();
        $marked = [];

        foreach ($parameter ?? [] as $p) {
            if ($p->getAttributes(\SensitiveParameter::class) !== []) {
                $marked[] = $p->getName();
            }
        }

        self::assertSame(['iban'], $marked);
    }

    public function testTheTraceOfAFailingConstructionHoldsTheIbanMasked(): void
    {
        try {
            new AddressBookRequest(iban: self::IBAN);
            self::fail('A request without a name or a company must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::IBAN, $e->getTraceAsString());

            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === AddressBookRequest::class) {
                    self::assertStringNotContainsString(self::IBAN, (string) json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR));
                }
            }
        }
    }

    // -- The query -----------------------------------------------------------------------------------

    public function testTheQueryHoldsOnlyWhatWasSet(): void
    {
        self::assertSame([], (new AddressBookQuery())->toArray());
        self::assertSame(['page' => 2, 'search' => 'Kowalski', 'ids' => [1, 2], 'onlyDefaultPickup' => true], (new AddressBookQuery(2, 'Kowalski', [1, 2], true))->toArray());
        self::assertSame(['onlyDefaultPickup' => false], (new AddressBookQuery(onlyDefaultPickup: false))->toArray(), 'false is a value.');
    }

    public function testTheQueryTakesEntriesAsIds(): void
    {
        $entry = AddressBookEntry::fromArray(Fixtures::json('address-book/entry'));

        self::assertSame(['ids' => [380861, 7]], (new AddressBookQuery(ids: [$entry, 7]))->toArray());
        self::assertSame([], (new AddressBookQuery(ids: [new AddressBookEntry()]))->toArray(), 'An entry without an id adds nothing.');
    }

    public function testABlankSearchIsLeftOut(): void
    {
        self::assertSame([], (new AddressBookQuery(search: '   '))->toArray());
    }

    public function testThePageStartsAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AddressBookQuery(page: 0);
    }
}
