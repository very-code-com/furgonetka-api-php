<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookEntry;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookRequest;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\AddressBookQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The address book through the client. Every answer here is a real body from the sandbox; what goes
 * out is read back from the recorded requests.
 */
final class FurgonetkaApiClientAddressBookTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';

    /** Exactly 15 characters: a trace cuts string arguments to 15, so a shorter secret could never show up. */
    private const IBAN = 'PL1234567890123';

    private static function request(): AddressBookRequest
    {
        return new AddressBookRequest(name: 'Phase Seven', company: 'Phase 7 test', email: 'phase7@example.com', street: 'Prosta 51', postcode: '00-838', city: 'Warszawa', countryCode: 'PL', phone: '500200300');
    }

    // -- Reading --------------------------------------------------------------------------------------

    public function testThePageIsAskedForWithNoQueryByDefault(): void
    {
        $h = new ClientHarness([Fixtures::response('address-book/list')]);

        $page = $h->client->listAddressBook();

        self::assertSame(['GET ' . self::HOST . '/account/address-book'], $h->sent());
        self::assertCount(3, $page);
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Accept'));
        self::assertNull($h->transport->requests[0]->body);
    }

    public function testTheQueryGoesInTheQueryString(): void
    {
        $h = new ClientHarness([Fixtures::response('address-book/list-search')]);

        $page = $h->client->listAddressBook(new AddressBookQuery(page: 2, search: 'Kowalski ą', onlyDefaultPickup: true));

        $url = (string) parse_url($h->transport->requests[0]->url, PHP_URL_QUERY);
        parse_str($url, $query);
        self::assertSame(['page' => '2', 'search' => 'Kowalski ą', 'onlyDefaultPickup' => 'true'], $query);
        self::assertCount(1, $page);
    }

    public function testEntriesCanBeAskedForByIdWithEntriesOrNumbers(): void
    {
        $h = new ClientHarness([Fixtures::response('address-book/list')]);

        $h->client->listAddressBook(new AddressBookQuery(ids: [380708, new AddressBookEntry(id: 380710)]));

        $query = (string) parse_url($h->transport->requests[0]->url, PHP_URL_QUERY);
        self::assertSame('ids%5B0%5D=380708&ids%5B1%5D=380710', $query);
    }

    public function testOneEntryIsFetchedByAnIdOrByAnEntry(): void
    {
        $h = new ClientHarness([Fixtures::response('address-book/entry'), Fixtures::response('address-book/entry')]);

        $byId = $h->client->fetchAddressBookEntry(380861);
        $byEntry = $h->client->fetchAddressBookEntry($byId);

        self::assertSame(['GET ' . self::HOST . '/account/address-book/380861', 'GET ' . self::HOST . '/account/address-book/380861'], $h->sent());
        self::assertSame('Phase Seven', $byEntry->fullName);
    }

    public function testAnEntryThatDoesNotExistIsA400WithTheCodeNotExists(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-address-book-not-exists', 400)]);

        try {
            $h->client->fetchAddressBookEntry(380861);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notExists'));
            self::assertSame(['/id'], $e->getFieldPaths());
        }
    }

    /** @return iterable<string, array{int|AddressBookEntry}> */
    public static function badIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
        yield 'an entry without an id' => [new AddressBookEntry()];
    }

    #[DataProvider('badIds')]
    public function testAnIdThatIsNotAPositiveNumberIsRefusedBeforeARequest(int|AddressBookEntry $id): void
    {
        $h = new ClientHarness();

        foreach ([
            static fn (FurgonetkaApiClient $c) => $c->fetchAddressBookEntry($id),
            static fn (FurgonetkaApiClient $c) => $c->replaceAddressBookEntry($id, self::request()),
            static fn (FurgonetkaApiClient $c) => $c->deleteAddressBookEntries([$id]),
        ] as $call) {
            try {
                $call($h->client);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('positive number', $e->getMessage());
            }
        }

        self::assertSame([], $h->transport->requests);
    }

    // -- Creating -----------------------------------------------------------------------------------------

    public function testAnEntryIsCreatedAndComesBackAsFurgonetkaStoredIt(): void
    {
        $h = new ClientHarness([Fixtures::response('address-book/entry')]);

        $entry = $h->client->createAddressBookEntry(self::request());

        self::assertSame(['POST ' . self::HOST . '/account/address-book'], $h->sent());
        self::assertSame(
            ['name' => 'Phase Seven', 'company' => 'Phase 7 test', 'email' => 'phase7@example.com', 'street' => 'Prosta 51', 'postcode' => '00-838', 'city' => 'Warszawa', 'country_code' => 'PL', 'phone' => '500200300'],
            $h->body(),
        );
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Content-Type'));
        self::assertSame(380861, $entry->id);
        self::assertSame('+48500200300', $entry->phone, 'Normalised by Furgonetka.');
    }

    public function testAnEntryFurgonetkaRefusesComesBackWithTheFieldErrors(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-address-book-validation', 400)]);

        try {
            $h->client->createAddressBookEntry(new AddressBookRequest(name: 'x', email: 'nope'));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/name', '/company', '/email'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('invalid'));
        }
    }

    // -- Replacing ------------------------------------------------------------------------------------------

    public function testAReplacementIsAPutOfTheWholeEntry(): void
    {
        $h = new ClientHarness([Fixtures::empty()]);

        $h->client->replaceAddressBookEntry(380861, self::request()->with(['city' => 'Krakow', 'postcode' => '31-021']));

        self::assertSame(['PUT ' . self::HOST . '/account/address-book/380861'], $h->sent());
        self::assertSame('Krakow', $h->body()['city']);
        self::assertSame('31-021', $h->body()['postcode']);
        self::assertSame('Phase Seven', $h->body()['name']);
    }

    public function testAnEntryCanBeChangedThroughItsOwnRequest(): void
    {
        $h = new ClientHarness([Fixtures::response('address-book/entry-updated'), Fixtures::empty()]);
        $entry = $h->client->fetchAddressBookEntry(380861);

        $h->client->replaceAddressBookEntry($entry, $entry->toRequest()->with(['city' => 'Krakow']));

        self::assertSame('PUT ' . self::HOST . '/account/address-book/380861', $h->sent()[1]);
        self::assertSame(
            ['name' => 'Phase Seven', 'company' => 'Phase 7 test', 'email' => 'phase7@example.com', 'street' => 'Prosta 51', 'postcode' => '31-021', 'city' => 'Krakow', 'country_code' => 'PL', 'phone' => '+48500200300', 'default_pickup' => false, 'default_my_address' => false, 'default_sender' => false, 'is_public' => true, 'uuid' => '909f39ef-2ee2-4b71-8679-84669dc46553'],
            $h->body(1),
        );
    }

    public function testReplacingAnEntryThatIsNotThereIsA400OnTheId(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-address-book-not-exists', 400)]);

        try {
            $h->client->replaceAddressBookEntry(1, self::request());
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notExists'));
        }
    }

    // -- Deleting -----------------------------------------------------------------------------------------------

    public function testEntriesAreDeletedInOneCallByIdOrByEntry(): void
    {
        $h = new ClientHarness([Fixtures::empty()]);

        $h->client->deleteAddressBookEntries([380861, new AddressBookEntry(id: 380862)]);

        self::assertSame(['DELETE ' . self::HOST . '/account/address-book'], $h->sent());
        self::assertSame(['entries' => [['id' => 380861], ['id' => 380862]]], $h->body());
    }

    public function testDeletingNothingIsRefusedBeforeARequest(): void
    {
        $h = new ClientHarness();

        try {
            $h->client->deleteAddressBookEntries([]);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('At least one', $e->getMessage());
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testOneUnknownEntryFailsTheWholeDeletion(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-address-book-delete-not-exists', 400)]);

        try {
            $h->client->deleteAddressBookEntries([380861, 1]);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/entries/0/id'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('notExists'));
        }
    }

    // -- Validating -----------------------------------------------------------------------------------------------

    public function testAnEntryFurgonetkaWouldTakeValidatesToNoErrors(): void
    {
        $h = new ClientHarness([Fixtures::empty()]);

        self::assertSame([], $h->client->validateAddressBookEntry(self::request()));
        self::assertSame(['POST ' . self::HOST . '/account/address-book/validate'], $h->sent());
        self::assertSame('Phase Seven', $h->body()['name']);
    }

    public function testAnEntryFurgonetkaWouldRefuseValidatesToTheListOfErrors(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-address-book-validation', 400)]);

        $errors = $h->client->validateAddressBookEntry(new AddressBookRequest(name: 'x', email: 'nope'));

        self::assertSame(['/name', '/company', '/email'], array_map(static fn ($e): ?string => $e->path, $errors));
        self::assertSame(['isEmpty', 'isEmpty', 'invalid'], array_map(static fn ($e): ?string => $e->code, $errors));
    }

    public function testAnErrorWithNoPathIsStillAnError(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-address-book-validate-empty', 400)]);

        $errors = $h->client->validateAddressBookEntry(new AddressBookRequest(name: 'x'));

        self::assertCount(1, $errors);
        self::assertSame('isEmpty', $errors[0]->code);
        self::assertNull($errors[0]->path, 'The sandbox sent "" and it reads as null.');
    }

    public function testAnythingButNoContentOrABadRequestIsAnException(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/server-error-unhandled', 500)]);

        $this->expectException(FurgonetkaApiServerException::class);

        $h->client->validateAddressBookEntry(self::request());
    }

    public function testARefusedTokenIsAnAuthException(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/unauthorized-bad-token', 401)]);

        $this->expectException(FurgonetkaApiAuthException::class);

        $h->client->validateAddressBookEntry(self::request());
    }

    // -- The IBAN ----------------------------------------------------------------------------------------------------

    public function testTheIbanGoesOutInTheBodyAndIsNeverLoggedOrTraced(): void
    {
        self::assertSame(15, strlen(self::IBAN));
        $h = new ClientHarness([Fixtures::response('errors/server-error-unhandled', 500)]);

        try {
            $h->client->createAddressBookEntry(self::request()->with(['iban' => self::IBAN]));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiServerException $e) {
            self::assertSame(self::IBAN, $h->body()['iban'], 'It has to reach Furgonetka.');
            self::assertStringNotContainsString(self::IBAN, $e->getTraceAsString());
            self::assertStringNotContainsString(self::IBAN, print_r($e->getTrace(), true), 'The request object in the trace shows its redacted form.');
            self::assertStringNotContainsString(self::IBAN, $e->getDebugReport());
            self::assertStringNotContainsString(self::IBAN, $h->logger->dump());
        }
    }

    public function testTheIbanIsNotInTheTraceWhenTheNetworkFailsEither(): void
    {
        // No scripted answer: FakeTransport throws from inside send(), so the trace holds the client's own
        // frames. An exception built in the test would carry the test's stack and prove nothing.
        $h = new ClientHarness();

        try {
            $h->client->createAddressBookEntry(self::request()->with(['iban' => self::IBAN]));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertSame('0', (string) ini_get('zend.exception_ignore_args'), 'phpunit.xml keeps the trace arguments, so this test can fail.');
            self::assertStringNotContainsString(self::IBAN, print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::IBAN, $e->getMessage());
        }

        self::assertStringNotContainsString(self::IBAN, $h->logger->dump());
    }
}
