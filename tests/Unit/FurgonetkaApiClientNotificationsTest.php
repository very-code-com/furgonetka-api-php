<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use VeryCodeCom\FurgonetkaApi\Dto\GeneralNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\NotificationSettingsRequest;
use VeryCodeCom\FurgonetkaApi\Dto\OrderNotificationRule;
use VeryCodeCom\FurgonetkaApi\Dto\ShippingNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookLog;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookSettings;
use VeryCodeCom\FurgonetkaApi\Enum\BalanceEffect;
use VeryCodeCom\FurgonetkaApi\Enum\BillGroup;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiCommandTimeoutException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;
use VeryCodeCom\FurgonetkaApi\Tests\Support\ClientHarness;
use VeryCodeCom\FurgonetkaApi\Tests\Support\FakeTransport;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * Notification settings, the webhook commands and the account's balance through the client. The
 * answers are real bodies from the sandbox unless a test says it is built from the specification.
 */
final class FurgonetkaApiClientNotificationsTest extends TestCase
{
    private const HOST = 'https://api.sandbox.furgonetka.pl';
    private const UUID = '8b881c52-6473-467a-a7c9-d0aa4c4f3bec';
    private const WEBHOOK = self::HOST . '/account/settings/notification/webhook';

    /** Exactly 15 characters: a trace cuts string arguments to 15, so a shorter secret could never show up. */
    private const TOKEN = '0123456789abcde';

    /** The uuid the client put at the end of the n-th request's URL. */
    private static function uuidOf(ClientHarness $h, int $index = 0): string
    {
        preg_match('#/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$#D', $h->transport->requests[$index]->url, $match);

        return $match[1] ?? throw new \LogicException('No version 4 UUID at the end of the URL.');
    }

    private static function settings(): NotificationSettingsRequest
    {
        return NotificationSettingsRequest::from(
            ShippingNotifications::fromArray(Fixtures::json('notifications/shipping')),
            GeneralNotifications::fromArray(Fixtures::json('notifications/general')),
            WebhookSettings::fromArray(Fixtures::json('webhook/settings-empty')),
        );
    }

    // -- Reading the settings ---------------------------------------------------------------------------------

    public function testTheGeneralOptionsAreRead(): void
    {
        $h = new ClientHarness([Fixtures::response('notifications/general')]);

        $general = $h->client->fetchGeneralNotifications();

        self::assertSame(['GET ' . self::HOST . '/account/settings/notification/general'], $h->sent());
        self::assertCount(5, $general);
        self::assertTrue($general->isEnabled('shipmentDox'));
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
    }

    public function testTheShippingNotificationsAreRead(): void
    {
        $h = new ClientHarness([Fixtures::response('notifications/shipping')]);

        $shipping = $h->client->fetchShippingNotifications();

        self::assertSame(['GET ' . self::HOST . '/account/settings/notification/shipping'], $h->sent());
        self::assertCount(11, $shipping);
        self::assertTrue($shipping->forState(TrackingState::Delivered)?->order->email);
    }

    // -- Saving the settings ------------------------------------------------------------------------------------

    public function testTheSettingsAreSavedInOnePostThatCarriesEverything(): void
    {
        $h = new ClientHarness([Fixtures::empty()]);

        $h->client->saveNotificationSettings(self::settings()->withOrder(new OrderNotificationRule(TrackingState::Delivered, webhook: true, email: true)));

        self::assertSame(['POST ' . self::HOST . '/account/settings/notification'], $h->sent());
        $body = $h->body();
        self::assertSame(['orderNotifications', 'trackingNotifications', 'emailBit', 'webhookUrl', 'webhookToken', 'webhookFormat'], array_keys($body));
        self::assertCount(11, $body['orderNotifications']);
        self::assertNull($body['webhookUrl'], 'Nulls are written: the save replaces the webhook too.');
        self::assertSame('application/vnd.furgonetka.v1+json', $h->transport->requests[0]->header('Content-Type'));
    }

    public function testAWebhookIsSavedWithItsTokenAndFormat(): void
    {
        $h = new ClientHarness([Fixtures::empty()]);

        $h->client->saveNotificationSettings(self::settings()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Xml));

        self::assertSame('https://shop.example/hook', $h->body()['webhookUrl']);
        self::assertSame(self::TOKEN, $h->body()['webhookToken'], 'It has to reach Furgonetka.');
        self::assertSame('xml', $h->body()['webhookFormat']);
    }

    public function testTheSaveIsLoggedWithoutTheAddressOrTheToken(): void
    {
        $h = new ClientHarness([Fixtures::empty(), Fixtures::empty()]);

        $h->client->saveNotificationSettings(self::settings());
        $h->client->saveNotificationSettings(self::settings()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json));

        self::assertTrue($h->logger->has(LogLevel::INFO, 'saving the notification settings'));
        self::assertSame([['webhook' => false], ['webhook' => true]], array_values(array_map(static fn (array $r): array => $r['context'], array_filter($h->logger->records, static fn (array $r): bool => str_contains((string) $r['message'], 'saving the notification')))));
        self::assertStringNotContainsString(self::TOKEN, $h->logger->dump());
        self::assertStringNotContainsString('shop.example', $h->logger->dump());
    }

    public function testAStateFurgonetkaDoesNotKnowIsABadRequestOnThatRule(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-notifications-state', 400)]);

        try {
            $h->client->saveNotificationSettings(self::settings()->withOrder(new OrderNotificationRule('no-such-state', true)));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/orderNotifications/0/state'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('notInArray'));
        }
    }

    public function testTheTokenIsNotInTheTraceOfARefusedSave(): void
    {
        self::assertSame(15, strlen(self::TOKEN));
        self::assertSame('0', (string) ini_get('zend.exception_ignore_args'), 'phpunit.xml keeps the trace arguments, so this test can fail.');
        $h = new ClientHarness([Fixtures::response('errors/bad-request-notifications-state', 400)]);

        try {
            $h->client->saveNotificationSettings(self::settings()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertStringNotContainsString(self::TOKEN, print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
            self::assertStringNotContainsString(self::TOKEN, $e->getDebugReport());
        }
    }

    public function testTheTokenIsNotInTheTraceWhenTheNetworkFailsEither(): void
    {
        // No scripted answer: FakeTransport throws from inside send(), so the trace holds the client's own frames.
        $h = new ClientHarness();

        try {
            $h->client->saveNotificationSettings(self::settings()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json));
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertStringNotContainsString(self::TOKEN, print_r($e->getTrace(), true));
        }
    }

    // -- Checking a webhook --------------------------------------------------------------------------------------

    public function testAWebhookCheckSendsTheAddressTokenAndFormatAndWaitsForTheVerdict(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('webhook/check-queueing'), Fixtures::response('webhook/check-error')]);

        $result = $h->client->checkWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json);

        self::assertTrue($result->hasFailed());
        self::assertSame([], $result->errors);
        $uuid = self::uuidOf($h);
        self::assertSame(['PUT ' . self::WEBHOOK . '/check-command/' . $uuid, 'GET ' . self::WEBHOOK . '/check-command/' . $uuid, 'GET ' . self::WEBHOOK . '/check-command/' . $uuid], $h->sent());
        self::assertSame(['webhookUrl' => 'https://shop.example/hook', 'webhookToken' => self::TOKEN, 'webhookFormat' => 'json'], $h->body());
        self::assertSame([1.0], $h->sleeper->sleeps);
    }

    public function testACheckThatWorkedIsAResultToo(): void
    {
        // The successful body was seen on the sandbox but not kept; this is the shape of any command answer.
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), FakeTransport::jsonResponse('{"status":"successful","datetime_change":"2026-09-29T11:15:52+02:00","errors":[],"uuid":"' . self::UUID . '"}')]);

        self::assertTrue($h->client->checkWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Xml)->isSuccessful());
        self::assertSame('xml', $h->body()['webhookFormat']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blankCheckInput(): iterable
    {
        yield 'no address' => ['', self::TOKEN];
        yield 'a blank address' => ['   ', self::TOKEN];
        yield 'no token' => ['https://shop.example/hook', ''];
        yield 'a blank token' => ['https://shop.example/hook', '  '];
    }

    #[DataProvider('blankCheckInput')]
    public function testACheckWithoutAnAddressOrATokenIsRefusedBeforeARequest(string $url, string $token): void
    {
        $h = new ClientHarness();

        try {
            $h->client->submitWebhookCheck($url, $token, WebhookFormat::Json);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('address and a token', $e->getMessage());
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testAnAddressThatIsNotAUrlIsABadRequestOnTheAddress(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-webhook-url', 400)]);

        try {
            $h->client->submitWebhookCheck('not a url', self::TOKEN, WebhookFormat::Json);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/webhookUrl'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('regexNotMatch'));
        }
    }

    public function testACheckCanBeSubmittedAndReadSeparatelyAndAnUnknownOneIsA400(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('webhook/check-queueing'), Fixtures::response('errors/bad-request-command-not-exists', 400)]);

        $uuid = $h->client->submitWebhookCheck('https://shop.example/hook', self::TOKEN, WebhookFormat::Json, self::UUID);

        self::assertSame(self::UUID, $uuid);
        self::assertFalse($h->client->fetchWebhookCheck($uuid)->isFinished());
        self::assertSame('GET ' . self::WEBHOOK . '/check-command/' . self::UUID, $h->sent()[1]);

        try {
            $h->client->fetchWebhookCheck(self::UUID);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'));
        }
    }

    public function testACheckThatNeverFinishesEndsInATimeoutThatCarriesTheUuid(): void
    {
        $answers = [Fixtures::response('commands/put-accepted')];

        for ($i = 0; $i < 200; $i++) {
            $answers[] = Fixtures::response('webhook/check-queueing');
        }

        $h = new ClientHarness($answers);

        try {
            $h->client->checkWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json);
            self::fail('Expected a timeout.');
        } catch (FurgonetkaApiCommandTimeoutException $e) {
            self::assertSame(self::uuidOf($h), $e->commandUuid());
            self::assertSame('queueing', $e->lastStatus());
        }
    }

    public function testTheCheckTokenIsNotInTheTraceOfARefusedCheck(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-webhook-url', 400)]);

        try {
            $h->client->checkWebhook('not a url', self::TOKEN, WebhookFormat::Json);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertStringNotContainsString(self::TOKEN, print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
            self::assertStringNotContainsString(self::TOKEN, $e->getDebugReport());
        }

        self::assertStringNotContainsString(self::TOKEN, $h->logger->dump());
    }

    public function testTheCheckTokenIsNotInTheTraceWhenTheNetworkFailsEither(): void
    {
        // No scripted answer: FakeTransport throws from inside send(), so the trace holds the client's own frames.
        $h = new ClientHarness();

        try {
            $h->client->checkWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiTransportException $e) {
            self::assertStringNotContainsString(self::TOKEN, print_r($e->getTrace(), true));
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    // -- Sending a notification again -------------------------------------------------------------------------------

    public function testANotificationIsSentAgainByTheIdOfItsLogEntry(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted'), Fixtures::response('webhook/check-queueing'), Fixtures::response('webhook/resend-successful')]);

        $result = $h->client->resendWebhook(12345);

        self::assertTrue($result->isSuccessful());
        $uuid = self::uuidOf($h);
        self::assertSame(['PUT ' . self::WEBHOOK . '/resend-command/' . $uuid, 'GET ' . self::WEBHOOK . '/resend-command/' . $uuid, 'GET ' . self::WEBHOOK . '/resend-command/' . $uuid], $h->sent());
        self::assertSame(['id' => 12345], $h->body());
    }

    public function testALogEntryWorksAsTheId(): void
    {
        $h = new ClientHarness([Fixtures::response('commands/put-accepted')]);

        $h->client->submitWebhookResend(new WebhookLog(id: 777), self::UUID);

        self::assertSame(['id' => 777], $h->body());
        self::assertSame('PUT ' . self::WEBHOOK . '/resend-command/' . self::UUID, $h->sent()[0]);
    }

    /** @return iterable<string, array{int|WebhookLog}> */
    public static function badLogIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'an entry without an id' => [new WebhookLog()];
    }

    #[DataProvider('badLogIds')]
    public function testAnIdThatIsNotAPositiveNumberIsRefusedBeforeARequest(int|WebhookLog $log): void
    {
        $h = new ClientHarness();

        try {
            $h->client->resendWebhook($log);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('positive id', $e->getMessage());
            self::assertSame([], $h->transport->requests);
        }
    }

    public function testALogEntryFurgonetkaDoesNotHaveIsA400WithTheCodeInvalid(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-webhook-resend-not-found', 400)]);

        try {
            $h->client->submitWebhookResend(1);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertSame(['/id'], $e->getFieldPaths());
            self::assertTrue($e->hasErrorCode('invalid'));
        }
    }

    public function testAnUnknownResendIsA400AndAKnownOneCanBeReadSeparately(): void
    {
        $h = new ClientHarness([Fixtures::response('errors/bad-request-command-not-exists', 400), Fixtures::response('webhook/check-queueing')]);

        try {
            $h->client->fetchWebhookResend(self::UUID);
            self::fail('Expected an exception.');
        } catch (FurgonetkaApiBadRequestException $e) {
            self::assertTrue($e->hasErrorCode('commandNotExists'));
        }

        self::assertFalse($h->client->fetchWebhookResend(self::UUID)->isFinished());
        self::assertSame(self::WEBHOOK . '/resend-command/' . self::UUID, substr($h->sent()[1], 4));
    }

    // -- The balance ----------------------------------------------------------------------------------------------------

    public function testTheBalanceIsAskedForWithNoQueryByDefault(): void
    {
        $h = new ClientHarness([Fixtures::response('billing/balance')]);

        $balance = $h->client->fetchBalance();

        self::assertSame(['GET ' . self::HOST . '/account/balance'], $h->sent());
        self::assertSame(661.56, $balance->balance);
        self::assertCount(10, $balance);
        self::assertSame('Bearer ' . ClientHarness::TOKEN, $h->transport->requests[0]->header('Authorization'));
    }

    public function testTheFiltersGoInTheQueryStringAndThePageBeginsBelowTheLastId(): void
    {
        $h = new ClientHarness([Fixtures::response('billing/balance'), Fixtures::response('billing/balance-page2')]);

        $first = $h->client->fetchBalance(new BalanceQuery(items: 10, effect: BalanceEffect::Minus, dateStart: '2026-09-01', dateEnd: '2026-09-29', group: BillGroup::CourierServices));
        $second = $h->client->fetchBalance(new BalanceQuery(items: 10, lastId: $first->nextLastId()));

        parse_str((string) parse_url($h->transport->requests[0]->url, PHP_URL_QUERY), $query);
        self::assertSame(['items' => '10', 'balance_effect' => 'minus', 'date_start' => '2026-09-01', 'date_end' => '2026-09-29', 'product' => 'courier_services'], $query);
        parse_str((string) parse_url($h->transport->requests[1]->url, PHP_URL_QUERY), $query);
        self::assertSame(['last_id' => '10393138', 'items' => '10'], $query);
        self::assertCount(2, $second);
    }

    public function testTheServiceAgreementsOfAnAccountWithoutAnyAreAnEmptyList(): void
    {
        $h = new ClientHarness([Fixtures::response('billing/agreements')]);

        self::assertSame([], $h->client->listServiceAgreements());
        self::assertSame(['GET ' . self::HOST . '/account/services-agreements'], $h->sent());
    }

    public function testTheServiceAgreementsAreListed(): void
    {
        // From the specification, not a captured body: the sandbox account has no agreements.
        $h = new ClientHarness([FakeTransport::jsonResponse('{"services_agreements":[{"agreement_id":12,"service_id":345,"service":"dpd","name":"Moja umowa DPD"},{"agreement_id":13,"service_id":346,"service":"gls","name":"Umowa GLS"}]}')]);

        $agreements = $h->client->listServiceAgreements();

        self::assertCount(2, $agreements);
        self::assertSame(345, $agreements[0]->serviceId);
        self::assertSame(Carrier::Gls, $agreements[1]->carrier);
    }

    public function testThePlannedPricelistChanges(): void
    {
        $h = new ClientHarness([Fixtures::response('billing/pricelist-changes'), FakeTransport::jsonResponse('{"pricelist_changes":[{"date":"2026-11-01","service":"dpd","url":"https://furgonetka.pl/cennik"}]}')]);

        $none = $h->client->listPlannedPricelistChanges();
        $changes = $h->client->listPlannedPricelistChanges();

        self::assertSame([], $none);

        self::assertSame(['GET ' . self::HOST . '/account/planned-pricelist-changes', 'GET ' . self::HOST . '/account/planned-pricelist-changes'], $h->sent());
        self::assertSame('dpd', $changes[0]->service, 'The second answer is from the specification, the first is real.');
    }
}
