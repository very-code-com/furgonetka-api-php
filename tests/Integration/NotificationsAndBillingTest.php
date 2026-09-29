<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Integration;

use VeryCodeCom\FurgonetkaApi\Dto\NotificationSettingsRequest;
use VeryCodeCom\FurgonetkaApi\Dto\OrderNotificationRule;
use VeryCodeCom\FurgonetkaApi\Dto\TrackingNotificationRule;
use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Enum\BillGroup;
use VeryCodeCom\FurgonetkaApi\Enum\BillType;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;

/**
 * Notification settings, the webhook commands and the balance against the real sandbox.
 *
 * The settings are the account's own, so a test that saves them keeps the account as it was: it reads
 * all three parts first and saves them back in a `finally`, and every test that could leave a trace ends
 * by comparing the three reads. No webhook is ever saved here: a webhook needs a public receiver that
 * answers the check, and the payload was captured by hand (see `WebhookEvent`'s fixtures).
 */
final class NotificationsAndBillingTest extends SandboxTestCase
{
    private static ?FurgonetkaApiClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$client ??= $this->sharedClient();
    }

    private function client(): FurgonetkaApiClient
    {
        return self::$client ?? throw new \LogicException('The client is created in setUp().');
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} The three parts as the API sent them. */
    private function snapshot(): array
    {
        return [
            $this->client()->fetchGeneralNotifications()->raw(),
            $this->client()->fetchShippingNotifications()->raw(),
            $this->client()->fetchWebhookSettings()->raw(),
        ];
    }

    private function current(): NotificationSettingsRequest
    {
        return NotificationSettingsRequest::from($this->client()->fetchShippingNotifications(), $this->client()->fetchGeneralNotifications(), $this->client()->fetchWebhookSettings());
    }

    // -- The settings ----------------------------------------------------------------------------------------------

    public function testTheGeneralOptionsAndTheShippingGroupsAreThere(): void
    {
        $general = $this->client()->fetchGeneralNotifications();
        $shipping = $this->client()->fetchShippingNotifications();

        foreach (['shipmentDox', 'orderConfirmation', 'packageReport', 'codReport', 'resignTOCPricelistAttachment'] as $key) {
            self::assertNotNull($general->find($key), $key);
        }

        self::assertCount(11, $shipping);

        foreach ($shipping as $group) {
            self::assertNotNull($group->state, (string) $group->key . ' is a state this release knows.');
        }
    }

    public function testSavingWhatWasReadChangesNothing(): void
    {
        $before = $this->snapshot();

        $this->client()->saveNotificationSettings($this->current());

        self::assertSame($before, $this->snapshot());
    }

    public function testAChangeIsSavedAndReadBackAndThenUndone(): void
    {
        $before = $this->snapshot();
        $original = $this->current();

        try {
            $flipped = !($this->client()->fetchShippingNotifications()->forState(TrackingState::Transit)?->tracking->push ?? false);

            $this->client()->saveNotificationSettings($original->withTracking(new TrackingNotificationRule(TrackingState::Transit, false, $flipped))->withOrder(new OrderNotificationRule(TrackingState::Transit, false, $flipped, false, false)));

            $transit = $this->client()->fetchShippingNotifications()->forState(TrackingState::Transit);
            self::assertSame($flipped, $transit?->tracking->push);
            self::assertSame($flipped, $transit->order->push);
            self::assertSame($before[0], $this->client()->fetchGeneralNotifications()->raw(), 'The e-mail options carried over stay as they were.');
        } finally {
            $this->client()->saveNotificationSettings($original);
        }

        self::assertSame($before, $this->snapshot(), 'The account is as it was.');
    }

    public function testAStateFurgonetkaDoesNotKnowIsRefusedOnItsRule(): void
    {
        $before = $this->snapshot();

        try {
            $this->client()->saveNotificationSettings($this->current()->withOrder(new OrderNotificationRule('no-such-state', true)));
            self::fail('An unknown state must be refused.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('notInArray'));
            self::assertCount(1, $e->getFieldPaths());
            self::assertStringStartsWith('/orderNotifications/', $e->getFieldPaths()[0]);
            self::assertStringEndsWith('/state', $e->getFieldPaths()[0]);
        }

        self::assertSame($before, $this->snapshot(), 'A refused save changes nothing.');
    }

    // -- The webhook -------------------------------------------------------------------------------------------------

    public function testAWebhookThatWasNeverCheckedCannotBeSaved(): void
    {
        $before = $this->snapshot();

        try {
            $this->client()->saveNotificationSettings($this->current()->withWebhook('https://webhook-that-does-not-exist.invalid/hook', '0123456789abcde', WebhookFormat::Json));
            self::fail('Furgonetka must refuse an address that did not pass a check.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/webhookUrl'], $e->getFieldPaths());
        } finally {
            if ($this->snapshot() !== $before) {
                $this->client()->saveNotificationSettings($this->current()->withWebhook(null, null, null));
            }
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testACheckOfAnAddressThatCannotBeReachedEndsAsAnErrorWithNothingListed(): void
    {
        $result = $this->client()->checkWebhook('https://webhook-that-does-not-exist.invalid/hook', '0123456789abcde', WebhookFormat::Json);

        self::assertTrue($result->hasFailed(), (string) $result->statusValue);
        self::assertSame([], $result->errors);
        self::assertNotSame('https://webhook-that-does-not-exist.invalid/hook', $this->client()->fetchWebhookSettings()->url, 'A check saves nothing.');
    }

    public function testACheckOfAnAddressThatIsNotAUrlIsRefused(): void
    {
        try {
            $this->client()->submitWebhookCheck('not a url', '0123456789abcde', WebhookFormat::Json);
            self::fail('Furgonetka must refuse a text that is not a URL.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/webhookUrl'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('regexNotMatch'));
        }
    }

    public function testALogEntryThatDoesNotExistCannotBeSentAgain(): void
    {
        try {
            $this->client()->submitWebhookResend(1);
            self::fail('There is no such log entry.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/id'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('invalid'));
        }
    }

    public function testAnUnknownWebhookCommandIsRefused(): void
    {
        foreach ([fn () => $this->client()->fetchWebhookCheck('8b881c52-6473-467a-a7c9-d0aa4c4f3bec'), fn () => $this->client()->fetchWebhookResend('8b881c52-6473-467a-a7c9-d0aa4c4f3bec')] as $call) {
            try {
                $call();
                self::fail('There is no such command.');
            } catch (FurgonetkaApiBadRequestException $e) {
                self::assertTrue($e->hasErrorCode('commandNotExists'));
            }
        }
    }

    // -- The balance ---------------------------------------------------------------------------------------------------

    public function testTheBalanceListsTheNewestOperationsFirstAndAgreesWithItself(): void
    {
        $balance = $this->client()->fetchBalance(new BalanceQuery(items: 10));

        self::assertSame('PLN', $balance->currency);
        self::assertNotNull($balance->balance);
        self::assertGreaterThanOrEqual(count($balance), (int) $balance->billsCount);
        self::assertLessThanOrEqual(10, count($balance));

        if (count($balance) === 0) {
            self::markTestSkipped('The sandbox account has no operation yet.');
        }

        self::assertEqualsWithDelta($balance->balance, $balance->bills[0]->balanceAfter, 0.001, 'The newest operation ends at the balance.');

        $previous = PHP_INT_MAX;

        foreach ($balance as $bill) {
            self::assertLessThan($previous, (int) $bill->id, 'Newest first.');
            self::assertEqualsWithDelta((float) $bill->balanceBefore + (float) $bill->amount, (float) $bill->balanceAfter, 0.001);
            $previous = (int) $bill->id;
        }
    }

    public function testThePagesFollowEachOtherByThePageNumberAndByTheLastId(): void
    {
        $first = $this->client()->fetchBalance(new BalanceQuery(items: 2));

        if (count($first) < 2 || (int) $first->billsCount < 4) {
            self::markTestSkipped('The account has too few operations to page.');
        }

        $byPage = $this->client()->fetchBalance(new BalanceQuery(page: 2, items: 2));
        $byId = $this->client()->fetchBalance(new BalanceQuery(items: 2, lastId: $first->nextLastId()));

        self::assertCount(2, $first);
        self::assertSame([$byPage->bills[0]->id, $byPage->bills[1]->id], [$byId->bills[0]->id, $byId->bills[1]->id]);
        self::assertLessThan((int) $first->nextLastId(), (int) $byId->bills[0]->id);
    }

    public function testTheFiltersSelectByDirectionAndGroup(): void
    {
        foreach ($this->client()->fetchBalance(new BalanceQuery(items: 20, effect: BalanceEffect::Minus)) as $bill) {
            self::assertLessThan(0, $bill->amount, (string) $bill->id);
        }

        foreach ($this->client()->fetchBalance(new BalanceQuery(items: 20, effect: BalanceEffect::Plus)) as $bill) {
            self::assertGreaterThan(0, $bill->amount, (string) $bill->id);
        }

        foreach ($this->client()->fetchBalance(new BalanceQuery(items: 20, group: BillGroup::HaulerCommission)) as $bill) {
            self::assertSame(BillType::ExchangeCommission, $bill->type, (string) $bill->id);
        }
    }

    public function testADateRangeKeepsToTheDays(): void
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw'));
        $balance = $this->client()->fetchBalance(new BalanceQuery(items: 50, dateStart: $today, dateEnd: $today));

        foreach ($balance as $bill) {
            self::assertSame($today->format('Y-m-d'), $bill->datetime?->format('Y-m-d'), (string) $bill->id);
        }
    }

    public function testTheContractsOfYourOwnAndTheAnnouncedPricelistChangesAreLists(): void
    {
        foreach ($this->client()->listServiceAgreements() as $agreement) {
            self::assertNotNull($agreement->serviceId);
        }

        foreach ($this->client()->listPlannedPricelistChanges() as $change) {
            self::assertNotNull($change->service);
        }

        self::addToAssertionCount(1);
    }
}
