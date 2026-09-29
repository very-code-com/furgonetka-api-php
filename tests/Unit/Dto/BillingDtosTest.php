<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\Balance;
use VeryCodeCom\FurgonetkaApi\Dto\Bill;
use VeryCodeCom\FurgonetkaApi\Dto\PricelistChange;
use VeryCodeCom\FurgonetkaApi\Dto\ServiceAgreement;
use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Enum\BillGroup;
use VeryCodeCom\FurgonetkaApi\Enum\BillProduct;
use VeryCodeCom\FurgonetkaApi\Enum\BillType;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The balance and what comes with it. The balance is a real body from the sandbox. The agreements and
 * the price list changes are empty on the sandbox account, so the entries are built from the field
 * names of the specification, and every test that does says so.
 */
final class BillingDtosTest extends TestCase
{
    public function testTheBalanceCarriesTheTotalsNextToTheOperations(): void
    {
        $balance = Balance::fromArray(Fixtures::json('billing/balance'));

        self::assertCount(10, $balance);
        self::assertSame(46, $balance->billsCount, 'How many operations there are, not how many came.');
        self::assertSame(-42.82, $balance->billsSum);
        self::assertSame(661.56, $balance->balance);
        self::assertSame('PLN', $balance->currency);
        self::assertNull($balance->creditUsage);
        self::assertSame(['2026-09', '2026-08'], $balance->filterMonths);
        self::assertSame(661.56, $balance->balanceWithoutHaulerCommission);
    }

    public function testARefundOperation(): void
    {
        $bill = Balance::fromArray(Fixtures::json('billing/balance'))->bills[0];

        self::assertSame(10393460, $bill->id);
        self::assertSame(508899, $bill->companyId);
        self::assertSame(BillProduct::Courier, $bill->product);
        self::assertSame('kurier', $bill->productValue);
        self::assertSame(BillType::Refund, $bill->type);
        self::assertSame('zwrot', $bill->typeValue);
        self::assertSame(640.15, $bill->balanceBefore);
        self::assertSame(21.41, $bill->amount);
        self::assertSame(661.56, $bill->balanceAfter);
        self::assertSame('PLN', $bill->currency);
        self::assertSame('11287102', $bill->transaction);
        self::assertSame(11287102, $bill->billsHistoryId);
        self::assertSame('Shipment cancellation', $bill->description);
        self::assertSame('Shipment cancellation (0000888304124Q)', $bill->descriptionFormatted);
        self::assertSame('2026-09-29 10:43:12', $bill->datetime?->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Warsaw', $bill->datetime->getTimezone()->getName());
        self::assertNull($bill->explainUrl);
    }

    public function testAShipmentIsChargedWithANegativeAmountAndItsIdAsTheTransaction(): void
    {
        $bill = Balance::fromArray(Fixtures::json('billing/balance'))->bills[1];

        self::assertSame(BillType::Shipment, $bill->type);
        self::assertSame('wysyłka', $bill->typeValue);
        self::assertSame(-21.41, $bill->amount);
        self::assertSame('21354201', $bill->transaction, 'The id of the shipment for this kind of operation.');
        self::assertNull($bill->description, 'An empty description is null; the formatted one has the text.');
        self::assertSame('Parcel 0000888304124Q', $bill->descriptionFormatted);
    }

    public function testAnOperationNamesTheShipmentsItIsAbout(): void
    {
        $package = Balance::fromArray(Fixtures::json('billing/balance'))->bills[0]->packages[0];

        self::assertSame('0000888304124Q', $package->packageNo);
        self::assertSame('21354201', $package->packageId);
        self::assertSame('Anulowana', $package->status);
        self::assertSame('anulowana', $package->state);
        self::assertSame('dpd', $package->service);
        self::assertSame(Carrier::Dpd, $package->carrier);
        self::assertSame(675316, $package->userId);
    }

    public function testTheCommissionOfTheFreightExchangeIsKnownThoughTheSpecificationDoesNotListIt(): void
    {
        $balance = Balance::fromArray(Fixtures::json('billing/balance-exchange-commission'));
        $bill = $balance->bills[0];

        self::assertSame(BillType::ExchangeCommission, $bill->type);
        self::assertSame('gielda_commission', $bill->typeValue);
        self::assertSame([], $bill->packages);
        self::assertSame(-4.18, $bill->amount);
        self::assertSame(4, $balance->billsCount);
        self::assertSame(-4.18, $balance->billsSum);
    }

    public function testTheNextPageStartsBelowTheLastOperationOfThisOne(): void
    {
        self::assertSame(10393138, Balance::fromArray(Fixtures::json('billing/balance'))->nextLastId());
        self::assertSame(10393197, Balance::fromArray(Fixtures::json('billing/balance-page2'))->nextLastId());
        self::assertNull(Balance::fromArray(['bills' => []])->nextLastId());
    }

    public function testAKindOrProductThisReleaseDoesNotKnowKeepsTheRawValue(): void
    {
        $bill = Bill::fromArray(['id' => 1, 'product' => 'lotnisko', 'type' => 'premia', 'amount' => 2, 'transaction' => 5]);

        self::assertNull($bill->product);
        self::assertSame('lotnisko', $bill->productValue);
        self::assertNull($bill->type);
        self::assertSame('premia', $bill->typeValue);
        self::assertSame(2.0, $bill->amount);
        self::assertSame('5', $bill->transaction);
    }

    public function testTheLinkThatExplainsAChargeCarriesItsPhrase(): void
    {
        // From the specification (an object with `url` and `phrase`); every operation the sandbox
        // showed had null here.
        $bill = Bill::fromArray(['id' => 1, 'url_to_explain_charge' => ['url' => 'https://furgonetka.pl/help/1', 'phrase' => 'weight correction']]);

        self::assertSame('https://furgonetka.pl/help/1', $bill->explainUrl);
        self::assertSame('weight correction', $bill->explainPhrase);
    }

    public function testTheWholeOperationAndTheWholeBalanceAreKept(): void
    {
        $data = Fixtures::json('billing/balance');
        $balance = Balance::fromArray($data);

        self::assertSame($data, $balance->raw());
        self::assertSame($data['bills'][0], $balance->bills[0]->raw());
    }

    public function testTheBalanceIsIterable(): void
    {
        $ids = array_map(static fn (Bill $b): ?int => $b->id, iterator_to_array(Balance::fromArray(Fixtures::json('billing/balance-page2'))));

        self::assertSame([10393429, 10393197], $ids);
    }

    public function testTheServiceAgreementsOfAnAccountWithoutAnyAreEmpty(): void
    {
        self::assertSame([], Fixtures::json('billing/agreements')['services_agreements']);
        self::assertSame([], Fixtures::json('billing/pricelist-changes')['pricelist_changes']);
    }

    public function testAServiceAgreement(): void
    {
        // From the specification, not a captured body: the sandbox account has none.
        $agreement = ServiceAgreement::fromArray(['agreement_id' => 12, 'service_id' => 345, 'service' => 'dpd', 'name' => 'Moja umowa DPD']);

        self::assertSame(12, $agreement->agreementId);
        self::assertSame(345, $agreement->serviceId);
        self::assertSame('dpd', $agreement->service);
        self::assertSame(Carrier::Dpd, $agreement->carrier);
        self::assertSame('Moja umowa DPD', $agreement->name);
        self::assertNull(ServiceAgreement::fromArray(['service' => 'newpost'])->carrier);
    }

    public function testAPlannedPricelistChange(): void
    {
        // From the specification, not a captured body: the sandbox announced none.
        $change = PricelistChange::fromArray(['date' => '2026-11-01', 'service' => 'dpd', 'url' => 'https://furgonetka.pl/cennik']);

        self::assertSame('2026-11-01', $change->date?->format('Y-m-d'));
        self::assertSame('dpd', $change->service);
        self::assertSame('https://furgonetka.pl/cennik', $change->url);
    }

    // -- The query ---------------------------------------------------------------------------------------

    public function testTheQueryHoldsOnlyWhatWasSet(): void
    {
        self::assertSame([], (new BalanceQuery())->toArray());
        self::assertSame(
            ['page' => 2, 'last_id' => 10393138, 'items' => 50, 'balance_effect' => 'minus', 'date_start' => '2026-09-01', 'date_end' => '2026-09-29', 'product' => 'courier_services'],
            (new BalanceQuery(2, 10393138, 50, BalanceEffect::Minus, '2026-09-01', '2026-09-29', BillGroup::CourierServices))->toArray(),
        );
    }

    public function testADateTimeObjectBecomesADay(): void
    {
        $query = new BalanceQuery(dateStart: new \DateTimeImmutable('2026-09-01 13:45:00'), dateEnd: new \DateTime('2026-09-29 23:59:59'));

        self::assertSame('2026-09-01', $query->dateStart);
        self::assertSame('2026-09-29', $query->dateEnd);
    }

    /** @return iterable<string, array{string}> */
    public static function datesThatAreNotDays(): iterable
    {
        yield 'with a time and a zone, the specification\'s own example' => ['2026-09-01T00:00:00Z'];
        yield 'with a space and a time' => ['2026-09-01 00:00:00'];
        yield 'day first' => ['01-09-2026'];
        yield 'with slashes' => ['2026/09/01'];
        yield 'a trailing newline' => ["2026-09-01\n"];
    }

    #[DataProvider('datesThatAreNotDays')]
    public function testADateThatIsNotADayIsRefused(string $date): void
    {
        foreach (['dateStart', 'dateEnd'] as $field) {
            try {
                $field === 'dateStart' ? new BalanceQuery(dateStart: $date) : new BalanceQuery(dateEnd: $date);
                self::fail('Furgonetka refuses this date, so it is refused here.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    public function testItemsAreBetweenOneAndAThousandBecauseALargerNumberIsSilentlyReadAsTen(): void
    {
        self::assertSame(['items' => 1], (new BalanceQuery(items: 1))->toArray());
        self::assertSame(['items' => 1000], (new BalanceQuery(items: 1000))->toArray());

        foreach ([0, -1, 1001] as $items) {
            try {
                new BalanceQuery(items: $items);
                self::fail('Out of range.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('between 1 and 1000', $e->getMessage());
            }
        }
    }

    public function testThePageStartsAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BalanceQuery(page: 0);
    }
}
