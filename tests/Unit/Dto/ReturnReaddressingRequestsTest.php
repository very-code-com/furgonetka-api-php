<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingReceiver;
use VeryCodeCom\FurgonetkaApi\Dto\SendToMeRequest;
use VeryCodeCom\FurgonetkaApi\Enum\PointService;

/**
 * The two request objects of part B that carry rules: the new receiver of a readdressing and the
 * data of a send-to-me link. The rules are the ones the documentation states and the sandbox
 * enforced (`isEmpty`); the county and the Ukrainian fields are not checked, because the sandbox
 * accepted an Irish address without a county.
 */
final class ReturnReaddressingRequestsTest extends TestCase
{
    // -- The new receiver of a readdressing -------------------------------------------------------

    public function testAReceiverIsWrittenWithoutTheFieldsThatWereNotGiven(): void
    {
        $receiver = new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '500200300', name: 'Piotr Zielinski');

        self::assertSame(
            ['name' => 'Piotr Zielinski', 'postcode' => '00-838', 'city' => 'Warszawa', 'street' => 'Prosta 51', 'phone' => '500200300'],
            $receiver->toArray(),
        );
    }

    public function testACompanyReceiverWithEveryOptionalField(): void
    {
        $receiver = new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '500200300', company: 'Firma XYZ', county: 'CARLOW', email: 'firma@example.com');

        self::assertSame(
            ['company' => 'Firma XYZ', 'postcode' => '00-838', 'city' => 'Warszawa', 'street' => 'Prosta 51', 'county' => 'CARLOW', 'email' => 'firma@example.com', 'phone' => '500200300'],
            $receiver->toArray(),
        );
    }

    /** @return iterable<string, array{callable(): ReaddressingReceiver, string}> */
    public static function receiversRefused(): iterable
    {
        yield 'no postcode' => [static fn (): ReaddressingReceiver => new ReaddressingReceiver(' ', 'Warszawa', 'Prosta 51', '500200300', name: 'A B'), 'postcode'];
        yield 'no city' => [static fn (): ReaddressingReceiver => new ReaddressingReceiver('00-838', '', 'Prosta 51', '500200300', name: 'A B'), 'city'];
        yield 'no street' => [static fn (): ReaddressingReceiver => new ReaddressingReceiver('00-838', 'Warszawa', "\n", '500200300', name: 'A B'), 'street'];
        yield 'no phone' => [static fn (): ReaddressingReceiver => new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '', name: 'A B'), 'phone'];
        yield 'neither a name nor a company' => [static fn (): ReaddressingReceiver => new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '500200300'), 'name or a company'];
        yield 'a blank name and a blank company' => [static fn (): ReaddressingReceiver => new ReaddressingReceiver('00-838', 'Warszawa', 'Prosta 51', '500200300', name: ' ', company: "\t"), 'name or a company'];
    }

    /** @param callable(): ReaddressingReceiver $build */
    #[DataProvider('receiversRefused')]
    public function testAReceiverWithoutWhatFurgonetkaRequiresIsRefused(callable $build, string $message): void
    {
        try {
            $build();
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    // -- The data of a send to me link ------------------------------------------------------------

    public function testAnAddressRequest(): void
    {
        $request = new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan Kowalski', street: 'Jasna 10/12', postcode: '00-001', city: 'Warszawa');

        self::assertSame(
            ['receiver' => ['email' => 'jan@example.com', 'phone' => '500100200', 'country_code' => 'PL', 'street' => 'Jasna 10/12', 'postcode' => '00-001', 'city' => 'Warszawa', 'name' => 'Jan Kowalski']],
            $request->toArray(),
            'The body the sandbox answered 200 to; no service key without a point.',
        );
    }

    public function testAPointRequestNeedsNoAddressButTheCarrier(): void
    {
        $request = new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan Kowalski', point: 'WAW099', service: PointService::Inpost);

        self::assertSame(
            ['receiver' => ['email' => 'jan@example.com', 'phone' => '500100200', 'country_code' => 'PL', 'name' => 'Jan Kowalski', 'point' => 'WAW099'], 'service' => 'inpost'],
            $request->toArray(),
        );
    }

    public function testACompanyRequestWithEveryOptionalField(): void
    {
        $request = new SendToMeRequest('firma@example.com', '500100200', 'UA', company: 'Firma', street: 'Vulytsia 1', postcode: '01001', city: 'Kyiv', county: 'Kyiv', buildingNumber: '1', flatNumber: '2');

        self::assertSame(
            ['email' => 'firma@example.com', 'phone' => '500100200', 'country_code' => 'UA', 'street' => 'Vulytsia 1', 'postcode' => '01001', 'city' => 'Kyiv', 'company' => 'Firma', 'county' => 'Kyiv', 'building_number' => '1', 'flat_number' => '2'],
            $request->toArray()['receiver'],
        );
    }

    public function testACarrierGivenAsTextIsTrimmed(): void
    {
        $request = new SendToMeRequest('jan@example.com', '500100200', 'PL', name: 'Jan', point: 'KRA010', service: ' inpost ');

        self::assertSame('inpost', $request->toArray()['service'] ?? null);
    }

    public function testAnIrishAddressWithoutACountyIsNotRefusedBecauseTheSandboxAcceptedIt(): void
    {
        $request = new SendToMeRequest('jan@example.com', '500100200', 'IE', name: 'Jan', street: 'Main St 1', postcode: 'D02 X285', city: 'Dublin');

        self::assertArrayNotHasKey('county', $request->toArray()['receiver']);
    }

    /** @return iterable<string, array{callable(): SendToMeRequest, string}> */
    public static function requestsRefused(): iterable
    {
        yield 'no e-mail' => [static fn (): SendToMeRequest => new SendToMeRequest(' ', '500100200', 'PL', name: 'A', street: 's', postcode: 'p', city: 'c'), 'email'];
        yield 'no phone' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '', 'PL', name: 'A', street: 's', postcode: 'p', city: 'c'), 'phone'];
        yield 'no country' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', "\n", name: 'A', street: 's', postcode: 'p', city: 'c'), 'countryCode'];
        yield 'neither a name nor a company' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', 'PL', street: 's', postcode: 'p', city: 'c'), 'name or a company'];
        yield 'no street without a point' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', 'PL', name: 'A', postcode: 'p', city: 'c'), 'street is required'];
        yield 'no postcode without a point' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', 'PL', name: 'A', street: 's', city: 'c'), 'postcode is required'];
        yield 'no city without a point' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', 'PL', name: 'A', street: 's', postcode: 'p', city: ' '), 'city is required'];
        yield 'a point without the carrier' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', 'PL', name: 'A', point: 'WAW099'), 'carrier that runs it'];
        yield 'a point with a blank carrier' => [static fn (): SendToMeRequest => new SendToMeRequest('a@b.pl', '500100200', 'PL', name: 'A', point: 'WAW099', service: ' '), 'carrier that runs it'];
    }

    /** @param callable(): SendToMeRequest $build */
    #[DataProvider('requestsRefused')]
    public function testARuleFromTheDocumentationIsEnforcedLocally(callable $build, string $message): void
    {
        try {
            $build();
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
