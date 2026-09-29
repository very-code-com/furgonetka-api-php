<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;
use VeryCodeCom\FurgonetkaApi\Webhook\WebhookEvent;

/**
 * The webhook notification. `event` and the two `event-check` fixtures are real bodies from the
 * sandbox (the first a notification after a simulated delivery, the others the sample of an address
 * check), signed with the token below, that of a throwaway catcher deleted afterwards. The
 * `doc-example` pair is the example of Furgonetka's webhook documentation, signed with its token.
 */
final class WebhookEventTest extends TestCase
{
    private const CATCHER_TOKEN = '7584a4e44d300826';

    private const DOCUMENTATION_TOKEN = 'tajnasól';

    public function testARealJsonNotificationIsRead(): void
    {
        $event = WebhookEvent::fromJson(Fixtures::raw('webhook/event'));

        self::assertSame(WebhookFormat::Json, $event->format);
        self::assertSame('21351614', $event->packageId);
        self::assertSame('1582893168850', $event->packageNo);
        self::assertSame('12', $event->partnerOrderId);
        self::assertSame(TrackingState::Delivered, $event->state);
        self::assertSame('delivered', $event->stateValue);
        self::assertSame('Doręczono.', $event->description);
        self::assertSame('2026-09-29 11:59:34', $event->datetime?->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Warsaw', $event->datetime->getTimezone()->getName());
        self::assertNull($event->branch, 'An empty string reads as null.');
        self::assertSame('d1f94df8e0cffe5db5d2054fa5e2d9ca', $event->control);
        self::assertSame(21351614, $event->raw()['package_id'], 'raw() keeps what was decoded.');
    }

    public function testARealJsonNotificationVerifiesWithTheTokenItWasSignedWith(): void
    {
        $event = WebhookEvent::fromJson(Fixtures::raw('webhook/event'));

        self::assertTrue($event->verify(self::CATCHER_TOKEN));
        self::assertFalse($event->verify(self::CATCHER_TOKEN . 'x'));
        self::assertFalse($event->verify(''), 'The checksum covers the token, so leaving it out fails.');
    }

    public function testTheSampleOfAnAddressCheckHasNoOrderReference(): void
    {
        $event = WebhookEvent::fromJson(Fixtures::raw('webhook/event-check'));

        self::assertNull($event->partnerOrderId);
        self::assertSame(TrackingState::Collected, $event->state);
        self::assertSame('Warszawa', $event->branch);
        self::assertTrue($event->verify(self::CATCHER_TOKEN));
    }

    public function testTheDocumentationExampleVerifiesInBothFormats(): void
    {
        $json = WebhookEvent::fromJson(Fixtures::raw('webhook/doc-example'));
        $xml = WebhookEvent::fromXml(Fixtures::xml('webhook/doc-example'));

        foreach ([$json, $xml] as $event) {
            self::assertSame('12345', $event->packageId);
            self::assertSame('003203043026', $event->packageNo);
            self::assertSame('67890', $event->partnerOrderId);
            self::assertSame(TrackingState::Delivered, $event->state);
            self::assertSame('Przesyłka doręczona', $event->description);
            self::assertSame('Elbląg', $event->branch);
            self::assertSame('b663ae3c1b5f286e88860b97ef0cc8e7', $event->control);
            self::assertTrue($event->verify(self::DOCUMENTATION_TOKEN));
            self::assertFalse($event->verify('tajnasol'));
        }

        self::assertSame(WebhookFormat::Xml, $xml->format);
    }

    public function testARealXmlNotificationIsRead(): void
    {
        $event = WebhookEvent::fromXml(Fixtures::xml('webhook/event-check'));

        self::assertSame(WebhookFormat::Xml, $event->format);
        self::assertSame('123456', $event->packageId);
        self::assertNull($event->partnerOrderId, 'An empty element reads as null.');
        self::assertSame(TrackingState::Collected, $event->state);
        self::assertSame('Doręczona', $event->description);
        self::assertTrue($event->verify(self::CATCHER_TOKEN));
        self::assertSame(
            ['package_id' => '123456', 'package_no' => '003203043026', 'partner_order_id' => '', 'tracking' => ['state' => 'collected', 'description' => 'Doręczona', 'datetime' => '2026-09-29 11:15:52', 'branch' => 'Warszawa'], 'control' => '46007c99bffdae3ce58fa5a776729543'],
            $event->raw(),
        );
    }

    public function testTheChecksumIsTakenOverTheTextAsItWasSent(): void
    {
        $id = WebhookEvent::fromArray(['package_id' => 1, 'package_no' => '2', 'tracking' => ['state' => 'transit'], 'control' => md5('12transit')]);
        $text = WebhookEvent::fromArray(['package_id' => '1', 'package_no' => '2', 'tracking' => ['state' => 'transit'], 'control' => md5('12transit')]);

        self::assertTrue($id->verify(''), 'A number and its text weigh the same, and a missing field counts as empty.');
        self::assertTrue($text->verify(''));
    }

    public function testEveryFieldIsPartOfTheChecksum(): void
    {
        $original = ['package_id' => 12345, 'package_no' => '003203043026', 'partner_order_id' => '67890', 'tracking' => ['state' => 'delivered', 'description' => 'Przesyłka doręczona', 'datetime' => '2019-07-05 09:19:09', 'branch' => 'Elbląg'], 'control' => 'b663ae3c1b5f286e88860b97ef0cc8e7'];

        self::assertTrue(WebhookEvent::fromArray($original)->verify(self::DOCUMENTATION_TOKEN));

        foreach (['package_id' => 12346, 'package_no' => '003203043027', 'partner_order_id' => '67891'] as $key => $value) {
            self::assertFalse(WebhookEvent::fromArray([$key => $value] + $original)->verify(self::DOCUMENTATION_TOKEN), $key);
        }

        foreach (['state' => 'transit', 'description' => 'Inny opis', 'datetime' => '2019-07-05 09:19:10', 'branch' => 'Gdańsk'] as $key => $value) {
            self::assertFalse(WebhookEvent::fromArray(['tracking' => [$key => $value] + $original['tracking']] + $original)->verify(self::DOCUMENTATION_TOKEN), $key);
        }
    }

    public function testTheOrderOfTheFieldsInTheChecksumIsTheDocumentedOne(): void
    {
        $control = md5('1' . 'no' . 'order' . 'transit' . 'text' . '2026-01-02 03:04:05' . 'depot' . 'salt');
        $event = WebhookEvent::fromArray(['control' => $control, 'package_id' => 1, 'package_no' => 'no', 'partner_order_id' => 'order', 'tracking' => ['branch' => 'depot', 'datetime' => '2026-01-02 03:04:05', 'description' => 'text', 'state' => 'transit']]);

        self::assertTrue($event->verify('salt'), 'The order of the keys in the body does not matter, the order of the checksum does.');
    }

    public function testXmlTextIsKeptExactlyAndEntitiesAreDecodedBeforeTheChecksum(): void
    {
        $control = md5('1' . 'no' . '' . 'transit' . 'A & B <ok>' . '2026-01-02 03:04:05' . ' depot ' . 'salt');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Notification><package_id>1</package_id><package_no>no</package_no><partner_order_id/>'
            . '<tracking><state>transit</state><description><![CDATA[A & B <ok>]]></description><datetime>2026-01-02 03:04:05</datetime><branch> depot </branch></tracking>'
            . '<control>' . $control . '</control></Notification>';

        $event = WebhookEvent::fromXml($xml);

        self::assertSame('A & B <ok>', $event->description);
        self::assertSame(' depot ', $event->branch, 'The checksum covers the whitespace, so it is not trimmed.');
        self::assertTrue($event->verify('salt'));

        $escaped = WebhookEvent::fromXml(str_replace('<![CDATA[A & B <ok>]]>', 'A &amp; B &lt;ok&gt;', $xml));

        self::assertSame('A & B <ok>', $escaped->description);
        self::assertTrue($escaped->verify('salt'));
    }

    public function testAStateThisReleaseDoesNotKnowKeepsTheRawValue(): void
    {
        $event = WebhookEvent::fromArray(['package_id' => 1, 'tracking' => ['state' => 'flying'], 'control' => md5('1flying')]);

        self::assertNull($event->state);
        self::assertSame('flying', $event->stateValue);
        self::assertTrue($event->verify(''), 'The checksum does not depend on the state being known.');
    }

    public function testANotificationWithoutAChecksumNeverVerifies(): void
    {
        $event = WebhookEvent::fromJson('{"package_id":1,"tracking":{"state":"transit"}}');

        self::assertNull($event->control);
        self::assertFalse($event->verify(''));
    }

    public function testAnEmptyObjectIsAnEventWithNothingInIt(): void
    {
        $event = WebhookEvent::fromJson('{}');

        self::assertNull($event->packageId);
        self::assertNull($event->state);
        self::assertNull($event->datetime);
        self::assertFalse($event->verify(''));
        self::assertSame([], $event->raw());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badJson(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['sekret-tresc {'];
        yield 'a list' => ['[1,2]'];
        yield 'a string' => ['"sekret-tresc"'];
    }

    #[DataProvider('badJson')]
    public function testABodyThatIsNotAJsonObjectIsRefusedWithoutEchoingIt(string $body): void
    {
        try {
            WebhookEvent::fromJson($body);
            self::fail('A body that is not a JSON object must be refused.');
        } catch (FurgonetkaApiResponseParseException $e) {
            self::assertStringNotContainsString('sekret-tresc', $e->getMessage());
            self::assertNull($e->getPrevious(), 'The JsonException holds the body in its trace.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badXml(): iterable
    {
        yield 'empty' => [''];
        yield 'not xml' => ['sekret-tresc <'];
        yield 'json' => ['{"package_id":1}'];
        yield 'another root' => ['<Other><package_id>1</package_id></Other>'];
        yield 'unclosed' => ['<Notification><package_id>1</Notification>'];
        yield 'doctype' => ['<?xml version="1.0"?><!DOCTYPE Notification [<!ENTITY x "boom">]><Notification><package_id>1</package_id></Notification>'];
        yield 'doctype with the entity used' => [sprintf('<?xml version="1.0"?><!DOCTYPE Notification [<!ENTITY x "boom">]><Notification><package_id>%s</package_id></Notification>', '&x;')];
        yield 'external dtd' => ['<?xml version="1.0"?><!DOCTYPE Notification SYSTEM "http://127.0.0.1:1/x.dtd"><Notification><package_id>1</package_id></Notification>'];
        yield 'doctype in utf-16' => ["\xFF\xFE" . (string) mb_convert_encoding('<?xml version="1.0"?><!DOCTYPE Notification [<!ENTITY x "boom">]><Notification><package_id>1</package_id></Notification>', 'UTF-16LE', 'UTF-8')];
        yield 'doctype in utf-7' => ['<?xml version="1.0" encoding="UTF-7"?>+ADw-!DOCTYPE Notification [+ADw-!ENTITY x "boom"+AD4-]+AD4-<Notification><package_id>1</package_id></Notification>'];
    }

    #[DataProvider('badXml')]
    public function testABodyThatIsNotANotificationDocumentIsRefused(string $body): void
    {
        try {
            WebhookEvent::fromXml($body);
            self::fail('A body that is not a plain Notification document must be refused.');
        } catch (FurgonetkaApiResponseParseException $e) {
            self::assertStringNotContainsString('sekret-tresc', $e->getMessage());
        }
    }

    public function testParsingXmlLeavesTheLibxmlErrorHandlingAsItFound(): void
    {
        foreach ([false, true] as $before) {
            libxml_use_internal_errors($before);

            try {
                WebhookEvent::fromXml('<Notification><a></Notification>');
                self::fail('A malformed body must be refused.');
            } catch (FurgonetkaApiResponseParseException) {
                self::assertSame($before, libxml_use_internal_errors($before), 'The setting is restored.');
                self::assertSame([], libxml_get_errors(), 'The errors of the parse do not stay for the next caller.');
            }
        }

        libxml_use_internal_errors(false);
    }

    public function testTheReplyIsInTheFormatTheNotificationCameIn(): void
    {
        $json = WebhookEvent::fromJson(Fixtures::raw('webhook/event'))->acknowledgement();

        self::assertSame(200, $json->statusCode);
        self::assertSame('application/json', $json->contentType);
        self::assertSame('{"status":"OK"}', $json->body);

        $xml = WebhookEvent::fromXml(Fixtures::xml('webhook/event-check'))->acknowledgement();

        self::assertSame(200, $xml->statusCode);
        self::assertSame('text/xml', $xml->contentType);
        self::assertSame('<?xml version="1.0" encoding="UTF-8"?><status>OK</status>', $xml->body);
        self::assertSame('OK', (string) simplexml_load_string($xml->body));
    }

    public function testARefusedNotificationIsAnsweredWithErrorAndStillWithA200(): void
    {
        $json = WebhookEvent::fromJson(Fixtures::raw('webhook/event'))->acknowledgement(false);
        $xml = WebhookEvent::fromXml(Fixtures::xml('webhook/event-check'))->acknowledgement(false);

        self::assertSame('{"status":"ERROR"}', $json->body);
        self::assertSame('<?xml version="1.0" encoding="UTF-8"?><status>ERROR</status>', $xml->body);
        self::assertSame(200, $json->statusCode, 'Furgonetka\'s own example answers a wrong checksum with a 200 too.');
        self::assertSame(200, $xml->statusCode);
    }

    public function testAnArrayCarriesItsFormatToTheReply(): void
    {
        $payload = ['package_id' => 1];

        self::assertSame('application/json', WebhookEvent::fromArray($payload)->acknowledgement()->contentType);
        self::assertSame('text/xml', WebhookEvent::fromArray($payload, WebhookFormat::Xml)->acknowledgement()->contentType);
    }
}
