<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\EmailNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\GeneralNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\NotificationSettingsRequest;
use VeryCodeCom\FurgonetkaApi\Dto\OrderNotificationRule;
use VeryCodeCom\FurgonetkaApi\Dto\ShippingNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\TrackingNotificationRule;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookSettings;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The notification settings: the two reads are real bodies from the sandbox (the account as it came,
 * with one option on), the request is what the sandbox accepted when it was built from them.
 */
final class NotificationDtosTest extends TestCase
{
    /** Exactly 15 characters: a trace cuts string arguments to 15, so a shorter secret could never show up. */
    private const TOKEN = '0123456789abcde';

    private static function shipping(): ShippingNotifications
    {
        return ShippingNotifications::fromArray(Fixtures::json('notifications/shipping'));
    }

    private static function general(): GeneralNotifications
    {
        return GeneralNotifications::fromArray(Fixtures::json('notifications/general'));
    }

    private static function request(?WebhookSettings $webhook = null): NotificationSettingsRequest
    {
        return NotificationSettingsRequest::from(self::shipping(), self::general(), $webhook ?? WebhookSettings::fromArray(Fixtures::json('webhook/settings-empty')));
    }

    // -- The general options -------------------------------------------------------------------------

    public function testTheGeneralOptionsAreReadByKey(): void
    {
        $general = self::general();

        self::assertCount(5, $general);
        self::assertTrue($general->isEnabled('shipmentDox'));
        self::assertFalse($general->isEnabled('packageReport'));
        self::assertFalse($general->isEnabled('codReport'));
        self::assertFalse($general->isEnabled('resignTOCPricelistAttachment'));
        self::assertNull($general->isEnabled('orderConfirmation'), 'The sandbox account had this one unset.');
        self::assertNull($general->isEnabled('nothingLikeIt'));
        self::assertNull($general->find('nothingLikeIt'));
        self::assertSame('shipmentDox', iterator_to_array($general)[0]->key);
    }

    public function testAnOptionCarriesItsNameAndDescription(): void
    {
        $option = self::general()->find('packageReport');

        self::assertSame('Monthly reports on delivered parcels', $option?->name);
        self::assertStringContainsString('detailed report', (string) $option->description);
    }

    public function testTheWholeBodyIsKept(): void
    {
        $data = Fixtures::json('notifications/general');

        self::assertSame($data, GeneralNotifications::fromArray($data)->raw());
        self::assertSame(Fixtures::json('notifications/shipping'), self::shipping()->raw());
    }

    // -- The shipping notifications -------------------------------------------------------------------

    public function testTheShippingGroupsCoverEveryTrackingState(): void
    {
        $shipping = self::shipping();

        self::assertCount(11, $shipping);

        foreach ($shipping as $group) {
            self::assertNotNull($group->state, (string) $group->key . ' is a state this release knows.');
            self::assertSame($group->state->value, $group->key);
        }
    }

    public function testAGroupCarriesTheChannelsForBothKindsOfShipment(): void
    {
        $delivered = self::shipping()->forState(TrackingState::Delivered);

        self::assertNotNull($delivered);
        self::assertSame(TrackingState::Delivered, $delivered->state);
        self::assertTrue($delivered->order->email, 'The one option the account had on.');
        self::assertFalse($delivered->order->sms);
        self::assertFalse($delivered->order->webhook);
        self::assertFalse($delivered->order->push);
        self::assertFalse($delivered->tracking->webhook);
        self::assertFalse($delivered->tracking->push);
    }

    public function testAGroupIsFoundByStateOrByItsKey(): void
    {
        $shipping = self::shipping();

        self::assertSame(TrackingState::ReadyForTransport, $shipping->forState('ready-for-transport')?->state);
        self::assertSame(TrackingState::DeliveryProblem, $shipping->forState(TrackingState::DeliveryProblem)?->state);
        self::assertNull($shipping->forState('lost'));
        self::assertNull($shipping->forState(TrackingState::Waiting), 'A state the API has no group for.');
    }

    public function testAStateThisReleaseDoesNotKnowKeepsItsKey(): void
    {
        $shipping = ShippingNotifications::fromArray(['settings' => [['key' => 'lost-in-space', 'name' => 'Lost', 'orderSettings' => ['email' => true], 'trackingSettings' => []]]]);
        $group = $shipping->forState('lost-in-space');

        self::assertNotNull($group);
        self::assertNull($group->state);
        self::assertSame('lost-in-space', $group->key);
        self::assertTrue($group->order->email);
        self::assertNull($group->order->sms, 'A channel the answer leaves out is null, not false.');
        self::assertNull($group->tracking->webhook);
    }

    // -- The rules -------------------------------------------------------------------------------------

    public function testAnOrderRuleTakesAStateAsAnEnumOrAsText(): void
    {
        $byEnum = new OrderNotificationRule(TrackingState::DeliveryProblem, webhook: true);
        $byText = new OrderNotificationRule('delivery-problem', true, false, false, true);

        self::assertSame('delivery-problem', $byEnum->stateName());
        self::assertSame(['state' => 'delivery-problem', 'webhook' => true, 'push' => null, 'sms' => null, 'email' => null], $byEnum->toArray());
        self::assertSame(['state' => 'delivery-problem', 'webhook' => true, 'push' => false, 'sms' => false, 'email' => true], $byText->toArray());
    }

    public function testATrackingRuleTakesAStateAsAnEnumOrAsText(): void
    {
        self::assertSame(['state' => 'delivered', 'webhook' => true, 'push' => null], (new TrackingNotificationRule(TrackingState::Delivered, true))->toArray());
        self::assertSame(['state' => 'x-y', 'webhook' => false, 'push' => true], (new TrackingNotificationRule('x-y', false, true))->toArray());
        self::assertSame('delivered', (new TrackingNotificationRule(TrackingState::Delivered))->stateName());
    }

    /** @return iterable<string, array{string}> */
    public static function badStates(): iterable
    {
        yield 'empty' => [''];
        yield 'capitals' => ['Collected'];
        yield 'underscore' => ['delivery_problem'];
        yield 'a space' => ['delivery problem'];
        yield 'a digit' => ['state1'];
        yield 'a leading hyphen' => ['-collected'];
        yield 'a trailing newline' => ["collected\n"];
    }

    #[DataProvider('badStates')]
    public function testAStateThatIsNotAnIdentifierIsRefused(string $state): void
    {
        foreach ([
            static fn () => new OrderNotificationRule($state),
            static fn () => new TrackingNotificationRule($state),
        ] as $build) {
            try {
                $build();
                self::fail('A state that is not an identifier must be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('identifier', $e->getMessage());
            }
        }
    }

    // -- The e-mail options -----------------------------------------------------------------------------

    public function testTheEmailOptionsComeFromTheGeneralReadUnderTheNamesTheSaveTakes(): void
    {
        $email = EmailNotifications::fromGeneral(self::general());

        self::assertTrue($email->shipmentDox);
        self::assertFalse($email->packageReport);
        self::assertFalse($email->codReport);
        self::assertNull($email->orderConfirmation);
        self::assertFalse($email->resignTocPricelistAttachment);
        self::assertSame(
            ['shipmentDox' => true, 'packageReport' => false, 'codReport' => false, 'orderConfirmation' => null, 'resignTOCPricelistAttachment' => false],
            $email->toArray(),
        );
    }

    public function testEachEmailOptionIsReadFromItsOwnKey(): void
    {
        $keys = ['shipmentDox' => 'shipmentDox', 'packageReport' => 'packageReport', 'codReport' => 'codReport', 'orderConfirmation' => 'orderConfirmation', 'resignTOCPricelistAttachment' => 'resignTOCPricelistAttachment'];

        foreach ($keys as $on) {
            $settings = [];

            foreach ($keys as $key) {
                $settings[] = ['key' => $key, 'enabled' => $key === $on];
            }

            $body = EmailNotifications::fromGeneral(GeneralNotifications::fromArray(['settings' => $settings]))->toArray();

            self::assertSame([$on], array_keys(array_filter($body)), "Only {$on} is on.");
        }
    }

    // -- The request -------------------------------------------------------------------------------------

    public function testTheRequestBuiltFromTheReadsHoldsWhatTheAccountHas(): void
    {
        $body = self::request()->toArray();

        self::assertCount(11, $body['orderNotifications']);
        self::assertCount(11, $body['trackingNotifications']);
        self::assertSame(['state' => 'collect-problem', 'webhook' => false, 'push' => false, 'sms' => false, 'email' => false], $body['orderNotifications'][0]);
        self::assertSame(['state' => 'collect-problem', 'webhook' => false, 'push' => false], $body['trackingNotifications'][0]);
        self::assertSame(['state' => 'delivered', 'webhook' => false, 'push' => false, 'sms' => false, 'email' => true], $body['orderNotifications'][7]);
        self::assertSame(['shipmentDox' => true, 'packageReport' => false, 'codReport' => false, 'orderConfirmation' => null, 'resignTOCPricelistAttachment' => false], $body['emailBit']);
        self::assertNull($body['webhookUrl']);
        self::assertNull($body['webhookToken']);
        self::assertNull($body['webhookFormat']);
        self::assertArrayNotHasKey('gieldaPushNotifications', $body, 'It cannot be read, so it is not carried over.');
    }

    public function testTheRequestCarriesTheWebhookOfTheAccount(): void
    {
        $request = self::request(WebhookSettings::fromArray(['url' => 'https://shop.example/hook', 'format' => 'xml', 'token' => self::TOKEN]));

        self::assertSame('https://shop.example/hook', $request->webhookUrl);
        self::assertSame(self::TOKEN, $request->webhookToken);
        self::assertSame(WebhookFormat::Xml, $request->webhookFormat);
        self::assertSame('xml', $request->toArray()['webhookFormat']);
    }

    public function testWithOrderReplacesTheRuleOfThatStateAndKeepsTheOthers(): void
    {
        $request = self::request()->withOrder(new OrderNotificationRule(TrackingState::Delivered, webhook: true));

        self::assertCount(11, $request->orderNotifications);
        $delivered = array_values(array_filter($request->orderNotifications, static fn (OrderNotificationRule $r): bool => $r->stateName() === 'delivered'));
        self::assertCount(1, $delivered);
        self::assertTrue($delivered[0]->webhook);
        self::assertNull($delivered[0]->email, 'The rule is replaced whole, not merged.');
        self::assertCount(11, $request->trackingNotifications);
    }

    public function testWithOrderAddsAStateTheReadDidNotHave(): void
    {
        $request = self::request()->withOrder(new OrderNotificationRule('brand-new', true));

        self::assertCount(12, $request->orderNotifications);
    }

    public function testWithTrackingReplacesOrAddsToo(): void
    {
        $replaced = self::request()->withTracking(new TrackingNotificationRule(TrackingState::Transit, true, true));
        $added = self::request()->withTracking(new TrackingNotificationRule('brand-new'));

        self::assertCount(11, $replaced->trackingNotifications);
        self::assertCount(12, $added->trackingNotifications);
        self::assertCount(11, $replaced->orderNotifications);
        $transit = array_values(array_filter($replaced->trackingNotifications, static fn (TrackingNotificationRule $r): bool => $r->stateName() === 'transit'));
        self::assertTrue($transit[0]->webhook);
        self::assertTrue($transit[0]->push);
    }

    public function testACopyLeavesTheOriginalAlone(): void
    {
        $original = self::request();
        $original->withOrder(new OrderNotificationRule(TrackingState::Delivered, webhook: true));
        $original->withEmail(new EmailNotifications(shipmentDox: false));
        $original->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json);

        self::assertSame(self::request()->toArray(), $original->toArray());
    }

    public function testWithEmailReplacesTheOptions(): void
    {
        $body = self::request()->withEmail(new EmailNotifications(shipmentDox: false, packageReport: true))->toArray();

        self::assertSame(['shipmentDox' => false, 'packageReport' => true, 'codReport' => null, 'orderConfirmation' => null, 'resignTOCPricelistAttachment' => null], $body['emailBit']);
    }

    public function testWithWebhookSetsAndSwitchesOffTheWebhook(): void
    {
        $on = self::request()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json);
        $off = $on->withWebhook(null, null, null);

        self::assertSame(['https://shop.example/hook', self::TOKEN, 'json'], [$on->toArray()['webhookUrl'], $on->toArray()['webhookToken'], $on->toArray()['webhookFormat']]);
        self::assertNull($off->toArray()['webhookUrl']);
        self::assertNull($off->toArray()['webhookToken']);
        self::assertNull($off->toArray()['webhookFormat']);
    }

    public function testACopyOtherThanWithWebhookKeepsTheWebhook(): void
    {
        $request = self::request()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Xml)->withOrder(new OrderNotificationRule(TrackingState::Transit, true));

        self::assertSame('https://shop.example/hook', $request->webhookUrl);
        self::assertSame(self::TOKEN, $request->webhookToken);
        self::assertSame(WebhookFormat::Xml, $request->webhookFormat);
    }

    public function testTheFreightExchangePushSettingIsSentOnlyWhenSetAndSurvivesACopy(): void
    {
        $request = new NotificationSettingsRequest([new OrderNotificationRule('collected')], [new TrackingNotificationRule('collected')], new EmailNotifications(), gieldaPushNotifications: true);

        self::assertTrue($request->toArray()['gieldaPushNotifications']);
        self::assertTrue($request->withEmail(new EmailNotifications())->toArray()['gieldaPushNotifications']);
        self::assertFalse((new NotificationSettingsRequest([new OrderNotificationRule('collected')], [new TrackingNotificationRule('collected')], new EmailNotifications(), gieldaPushNotifications: false))->toArray()['gieldaPushNotifications'], 'false is a value.');
    }

    public function testBothListsMustHaveAStateBecauseFurgonetkaRefusesAnEmptyOne(): void
    {
        $order = [new OrderNotificationRule('collected')];
        $tracking = [new TrackingNotificationRule('collected')];

        foreach ([[[], $tracking], [$order, []], [[], []]] as [$o, $t]) {
            try {
                new NotificationSettingsRequest($o, $t, new EmailNotifications());
                self::fail('An empty list must be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('at least one state', $e->getMessage());
            }
        }
    }

    public function testAWebhookAddressNeedsItsFormat(): void
    {
        $order = [new OrderNotificationRule('collected')];
        $tracking = [new TrackingNotificationRule('collected')];

        try {
            new NotificationSettingsRequest($order, $tracking, new EmailNotifications(), 'https://shop.example/hook', self::TOKEN);
            self::fail('An address without a format must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('needs its format', $e->getMessage());
        }

        self::assertNull((new NotificationSettingsRequest($order, $tracking, new EmailNotifications(), '  '))->webhookFormat, 'A blank address is no webhook, so it needs nothing.');
    }

    public function testReadsThatLeaveAListEmptyCannotBecomeARequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NotificationSettingsRequest::from(new ShippingNotifications(), self::general(), new WebhookSettings());
    }

    public function testAGroupWithoutAKeyIsSkippedWhenBuildingFromTheReads(): void
    {
        $shipping = ShippingNotifications::fromArray(['settings' => [['name' => 'No key'], ['key' => 'collected', 'orderSettings' => ['email' => true], 'trackingSettings' => ['push' => true]]]]);

        $body = NotificationSettingsRequest::from($shipping, self::general(), new WebhookSettings())->toArray();

        self::assertSame([['state' => 'collected', 'webhook' => null, 'push' => null, 'sms' => null, 'email' => true]], $body['orderNotifications']);
        self::assertSame([['state' => 'collected', 'webhook' => null, 'push' => true]], $body['trackingNotifications']);
    }

    // -- The webhook token is a secret ---------------------------------------------------------------------

    public function testTheWebhookTokenNeverShowsInADump(): void
    {
        self::assertSame(15, strlen(self::TOKEN));
        $request = self::request()->withWebhook('https://shop.example/hook', self::TOKEN, WebhookFormat::Json);

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString(self::TOKEN, print_r($request, true));
        self::assertStringContainsString('[redacted]', $dump);
        self::assertSame(self::TOKEN, $request->toArray()['webhookToken'], 'The body still carries it.');
    }

    public function testNoTokenShowsAsNullInADump(): void
    {
        self::assertStringNotContainsString('[redacted]', print_r(self::request(), true));
    }

    public function testEveryParameterThatCanCarryTheTokenIsMarkedSensitive(): void
    {
        $class = new \ReflectionClass(NotificationSettingsRequest::class);
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $marked = [];

        foreach ([$constructor, $class->getMethod('withWebhook'), $class->getMethod('copy')] as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                    $marked[] = $method->getName() . '::' . $parameter->getName();
                }
            }
        }

        self::assertSame(['__construct::webhookToken', 'withWebhook::token', 'copy::webhookToken'], $marked);
    }

    public function testTheTraceOfACallThatThrowsHoldsTheTokenMasked(): void
    {
        try {
            self::request()->withWebhook('https://shop.example/hook', self::TOKEN, null);
            self::fail('An address without a format must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
            $frames = 0;

            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === NotificationSettingsRequest::class) {
                    ++$frames;
                    self::assertStringNotContainsString(self::TOKEN, (string) json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR));
                }
            }

            self::assertGreaterThanOrEqual(3, $frames, 'The constructor, copy() and withWebhook() are all on the way.');
        }
    }
}
