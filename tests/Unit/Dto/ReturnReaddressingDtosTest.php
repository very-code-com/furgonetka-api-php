<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Command\CommandStatus;
use VeryCodeCom\FurgonetkaApi\Dto\PaymentRequest;
use VeryCodeCom\FurgonetkaApi\Dto\PriceEstimate;
use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingDisposition;
use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\ReturnDisposition;
use VeryCodeCom\FurgonetkaApi\Dto\SendToMeLink;
use VeryCodeCom\FurgonetkaApi\Enum\DispositionStatus;
use VeryCodeCom\FurgonetkaApi\Enum\PriceScenario;
use VeryCodeCom\FurgonetkaApi\Enum\ReaddressingStatus;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The answers about returns, readdressing and the send-to-me link. The sandbox refused every
 * return and readdressing it was asked for ("not possible"), so the success shapes here are built
 * from the specification and say so; only the send-to-me link is a captured body.
 */
final class ReturnReaddressingDtosTest extends TestCase
{
    // -- Dispositions on a shipment ---------------------------------------------------------------

    public function testAReturnOrderThatIsPaid(): void
    {
        // Shape from the specification (ReturnDisposition), not a captured body.
        $disposition = ReturnDisposition::fromArray(['status' => 'paid', 'datetime' => '2026-09-29T12:00:00+02:00']);

        self::assertSame(DispositionStatus::Paid, $disposition->status);
        self::assertSame('paid', $disposition->statusValue);
        self::assertTrue($disposition->isPaid());
        self::assertFalse($disposition->isAwaitingPayment());
        self::assertSame('2026-09-29T12:00:00+02:00', $disposition->datetime?->format(\DATE_ATOM));
    }

    public function testAReturnOrderThatWaitsForPaymentAndOneWithAStatusThisReleaseDoesNotKnow(): void
    {
        $waiting = ReturnDisposition::fromArray(['status' => 'new', 'datetime' => null]);
        $odd = ReturnDisposition::fromArray(['status' => 'refunded']);

        self::assertTrue($waiting->isAwaitingPayment());
        self::assertNull($waiting->datetime);
        self::assertNull($odd->status);
        self::assertSame('refunded', $odd->statusValue);
        self::assertFalse($odd->isPaid());
        self::assertFalse($odd->isAwaitingPayment());
        self::assertSame(['status' => 'refunded'], $odd->raw());
    }

    public function testAReaddressingOrderCarriesTheNewReceiverAsFlatFields(): void
    {
        // Shape from the specification (ReaddressingDisposition), not a captured body.
        $disposition = ReaddressingDisposition::fromArray([
            'status' => 'new',
            'datetime' => '2026-09-29T13:00:00+02:00',
            'name' => 'Zofia Wisniewska',
            'company' => null,
            'street' => 'Florianska 15',
            'postcode' => '31-021',
            'city' => 'Krakow',
            'country_code' => 'PL',
            'county' => null,
            'email' => 'zofia@example.com',
            'phone' => '600700800',
            'point' => null,
        ]);

        self::assertTrue($disposition->isAwaitingPayment());
        self::assertFalse($disposition->isPaid());
        self::assertSame('Zofia Wisniewska', $disposition->name);
        self::assertNull($disposition->company);
        self::assertSame('Florianska 15', $disposition->street);
        self::assertSame('31-021', $disposition->postcode);
        self::assertSame('Krakow', $disposition->city);
        self::assertSame('PL', $disposition->countryCode);
        self::assertSame('zofia@example.com', $disposition->email);
        self::assertSame('600700800', $disposition->phone, 'Plain text here, not the prefix and number of a shipment address.');
        self::assertSame('2026-09-29T13:00:00+02:00', $disposition->datetime?->format(\DATE_ATOM));
        self::assertSame('Krakow', $disposition->raw()['city']);
    }

    // -- Price ------------------------------------------------------------------------------------

    public function testAPrice(): void
    {
        // Shape from the specification (ReturnReaddressingCalculatePriceResult), not a captured body.
        $estimate = PriceEstimate::fromArray(['price' => 20.76, 'scenario' => null]);

        self::assertSame(20.76, $estimate->price);
        self::assertNull($estimate->scenario);
        self::assertNull($estimate->scenarioValue);
        self::assertFalse($estimate->requiresIndividualPricing());
        self::assertSame(['price' => 20.76, 'scenario' => null], $estimate->raw());
    }

    public function testAPriceFromAnIndividualScenarioIsNotFinal(): void
    {
        $estimate = PriceEstimate::fromArray(['price' => null, 'scenario' => 'individual_pricing_dhl_freight']);

        self::assertSame(PriceScenario::IndividualPricingDhlFreight, $estimate->scenario);
        self::assertSame('individual_pricing_dhl_freight', $estimate->scenarioValue);
        self::assertTrue($estimate->requiresIndividualPricing());
        self::assertNull($estimate->price);
    }

    public function testAScenarioThisReleaseDoesNotKnowKeepsTheRawValue(): void
    {
        $estimate = PriceEstimate::fromArray(['price' => '12.5', 'scenario' => 'by_weight']);

        self::assertNull($estimate->scenario);
        self::assertSame('by_weight', $estimate->scenarioValue);
        self::assertFalse($estimate->requiresIndividualPricing());
        self::assertSame(12.5, $estimate->price, 'A price sent as text is read as a number.');
    }

    // -- Payment links ------------------------------------------------------------------------------

    public function testTheLinksWhereAPersonPaysAndConfirms(): void
    {
        // Shape from the specification (ReaddressingCommandPutResponse), not a captured body.
        $request = PaymentRequest::fromArray(['uuid' => '8b881c52-6473-467a-a7c9-d0aa4c4f3bec', 'paymentUrl' => 'https://pay.example/1', 'summaryUrl' => 'https://shop.example/summary/1']);

        self::assertSame('8b881c52-6473-467a-a7c9-d0aa4c4f3bec', $request->uuid);
        self::assertSame('https://pay.example/1', $request->paymentUrl);
        self::assertSame('https://shop.example/summary/1', $request->summaryUrl);
    }

    public function testThereMayBeNothingToPay(): void
    {
        $request = PaymentRequest::fromArray(['uuid' => 'u', 'paymentUrl' => null, 'summaryUrl' => null]);

        self::assertNull($request->paymentUrl);
        self::assertNull($request->summaryUrl);
        self::assertSame(['uuid' => 'u', 'paymentUrl' => null, 'summaryUrl' => null], $request->raw());
    }

    // -- The state of a readdressing ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function readdressingBody(string $status = 'paid'): array
    {
        // Shape from the specification (ReaddressingResponse), not a captured body.
        return [
            'status' => 'successful',
            'datetime_change' => '2026-09-29T10:00:00+02:00',
            'errors' => [],
            'uuid' => '8b881c52-6473-467a-a7c9-d0aa4c4f3bec',
            'details' => [
                'newReceiver' => ['name' => 'Piotr Zielinski', 'street' => 'Prosta 51', 'postcode' => '00-838', 'city' => 'Warszawa', 'country_code' => 'PL', 'email' => 'piotr@example.com'],
                'price' => 12.5,
                'status' => $status,
                'info' => 'Paid by card',
                'packages_data' => ['packages_groups' => [
                    ['key' => 'a', 'service' => 'dpd', 'packages' => [['id' => 21353373], ['id' => 21353374]]],
                    ['key' => 'b', 'service' => 'dpd', 'packages' => [['id' => 21353374], ['id' => '21353375'], ['name' => 'no id']]],
                ]],
            ],
        ];
    }

    public function testTheStateOfAReaddressing(): void
    {
        $result = ReaddressingCommandResult::fromArray(self::readdressingBody());

        self::assertSame(CommandStatus::Successful, $result->status);
        self::assertTrue($result->isSuccessful());

        $details = $result->details;
        self::assertNotNull($details);
        self::assertSame('Piotr Zielinski', $details->newReceiver?->name);
        self::assertSame('00-838', $details->newReceiver->postcode);
        self::assertSame(12.5, $details->price);
        self::assertSame(ReaddressingStatus::Paid, $details->status);
        self::assertSame('paid', $details->statusValue);
        self::assertSame('Paid by card', $details->info);
        self::assertArrayHasKey('packages_groups', $details->packagesData);
    }

    public function testTheShipmentsOfAReaddressingAreListedOnceEach(): void
    {
        $details = ReaddressingCommandResult::fromArray(self::readdressingBody())->details;

        self::assertNotNull($details);
        self::assertSame(['21353373', '21353374', '21353375'], $details->packageIds(), 'Ids come as numbers or text, repeat across groups, and an entry without one is skipped.');
    }

    public function testEveryPaymentStateIsAMember(): void
    {
        foreach (['new' => ReaddressingStatus::New, 'paid' => ReaddressingStatus::Paid, 'cancelled' => ReaddressingStatus::Cancelled] as $value => $state) {
            self::assertSame($state, ReaddressingCommandResult::fromArray(self::readdressingBody($value))->details?->status);
        }
    }

    public function testAPaymentStateThisReleaseDoesNotKnowKeepsTheRawValue(): void
    {
        $details = ReaddressingCommandResult::fromArray(self::readdressingBody('refunded'))->details;

        self::assertNotNull($details);
        self::assertNull($details->status);
        self::assertSame('refunded', $details->statusValue);
    }

    public function testAReaddressingAnswerWithoutDetails(): void
    {
        $result = ReaddressingCommandResult::fromArray(['status' => 'queueing', 'uuid' => 'u', 'errors' => []]);

        self::assertNull($result->details);
        self::assertFalse($result->isFinished());
        self::assertSame([], ReaddressingCommandResult::fromArray(['details' => []])->details?->packageIds() ?? []);
    }

    // -- The send to me link -------------------------------------------------------------------------

    public function testTheSendToMeLinkOfAnAddressAndOfAPoint(): void
    {
        foreach (['address', 'point', 'company'] as $fixture) {
            $link = SendToMeLink::fromArray(Fixtures::json('send-to-me/' . $fixture));

            self::assertStringStartsWith('https://sandbox.furgonetka.pl/konto/dodawanie-paczki?sendToMeData=', (string) $link->url, $fixture);
            self::assertArrayHasKey('url', $link->raw());
        }
    }

    public function testAnAnswerWithoutAUrlHasNone(): void
    {
        self::assertNull(SendToMeLink::fromArray([])->url);
    }
}
