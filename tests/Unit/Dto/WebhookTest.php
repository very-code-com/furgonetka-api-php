<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookLog;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookLogPage;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookSettings;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Tests\Support\Fixtures;

/**
 * The webhook settings and the call log. The log is real: it holds only the deliveries that failed
 * (a successful one leaves just `lastSuccess`), and a successful resend removes the entry. A
 * configured webhook and a page with more than one entry are built from the field names of the
 * specification and say so: the page size was not reached, so paging was never seen (W24).
 */
final class WebhookTest extends TestCase
{
    /** Exactly 15 characters: a stack trace cuts string arguments to 15, so a shorter secret could never show up. */
    private const TOKEN = '0123456789abcde';

    public function testAnAccountWithoutAWebhookAnswersWithNulls(): void
    {
        $settings = WebhookSettings::fromArray(Fixtures::json('webhook/settings-empty'));

        self::assertNull($settings->url);
        self::assertNull($settings->format);
        self::assertNull($settings->formatValue);
        self::assertNull($settings->token);
        self::assertFalse($settings->isConfigured());
    }

    public function testAConfiguredWebhook(): void
    {
        // Shape from the specification (SettingsWebhook), not a captured body.
        $settings = WebhookSettings::fromArray(['url' => 'https://shop.example/hook', 'format' => 'json', 'token' => self::TOKEN]);

        self::assertTrue($settings->isConfigured());
        self::assertSame('https://shop.example/hook', $settings->url);
        self::assertSame(WebhookFormat::Json, $settings->format);
        self::assertSame('json', $settings->formatValue);
        self::assertSame(self::TOKEN, $settings->token);
        self::assertSame(self::TOKEN, $settings->raw()['token'], 'The token is there for whoever asks for it on purpose.');
    }

    public function testAFormatThisReleaseDoesNotKnowKeepsTheRawValue(): void
    {
        $settings = WebhookSettings::fromArray(['url' => 'https://shop.example/hook', 'format' => 'msgpack']);

        self::assertNull($settings->format);
        self::assertSame('msgpack', $settings->formatValue);
    }

    public function testTheTokenNeverShowsInADump(): void
    {
        $settings = WebhookSettings::fromArray(['url' => 'https://shop.example/hook', 'format' => 'xml', 'token' => self::TOKEN]);

        ob_start();
        var_dump($settings);
        print_r($settings);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringContainsString('[redacted]', $dump);
        self::assertStringContainsString('https://shop.example/hook', $dump, 'What is not secret stays readable.');
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($settings->__debugInfo()));
    }

    public function testAnUnsetTokenIsNotDressedUpAsARedactedOne(): void
    {
        self::assertNull(WebhookSettings::fromArray([])->__debugInfo()['token']);
    }

    public function testEveryParameterThatCanCarryTheTokenIsMarkedSensitive(): void
    {
        $marked = static fn (\ReflectionParameter $parameter): bool => $parameter->getAttributes(\SensitiveParameter::class) !== [];
        $constructor = (new \ReflectionClass(WebhookSettings::class))->getConstructor();

        self::assertNotNull($constructor);
        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $marked($parameter);
        }

        self::assertTrue($parameters['token']);
        self::assertTrue($parameters['raw']);
        self::assertTrue($marked((new \ReflectionMethod(WebhookSettings::class, 'fromArray'))->getParameters()[0]));
    }

    public function testTheTokenIsAbsentFromTheTraceOfAConstructorThatFails(): void
    {
        // A wrong type for `url` makes the constructor throw while `token` is one of its arguments.
        $trace = $this->traceOf(static fn () => (new \ReflectionClass(WebhookSettings::class))->newInstanceArgs([['not', 'a', 'string'], null, null, self::TOKEN]));

        self::assertStringNotContainsString(self::TOKEN, print_r($trace, true));
        self::assertStringContainsString('SensitiveParameterValue', print_r($trace, true), 'The argument is there, masked.');
    }

    public function testTheTokenIsAbsentFromTheTraceOfAFromArrayThatFails(): void
    {
        // The token text stands for the whole answer: a wrong type makes fromArray() throw with it as the argument.
        $trace = $this->traceOf(static fn () => (new \ReflectionMethod(WebhookSettings::class, 'fromArray'))->invoke(null, self::TOKEN));

        self::assertStringNotContainsString(self::TOKEN, print_r($trace, true));
        self::assertStringContainsString('SensitiveParameterValue', print_r($trace, true));
    }

    /**
     * The frames of {@see WebhookSettings} in the trace of what a call throws, arguments included
     * (phpunit.xml sets zend.exception_ignore_args=0; a production php.ini drops them, and this
     * test would then pass for the wrong reason). Frames of the test's own reflection call hold the
     * token by construction and are left out.
     *
     * @param callable(): mixed $call
     * @return non-empty-list<array<string, mixed>>
     */
    private function traceOf(callable $call): array
    {
        self::assertSame('0', (string) ini_get('zend.exception_ignore_args'));

        try {
            $call();
        } catch (\Throwable $e) {
            $frames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === WebhookSettings::class));

            if ($frames === []) {
                self::fail('The trace has no frame of WebhookSettings.');
            }

            return $frames;
        }

        self::fail('Expected the call to throw.');
    }

    public function testALogEntryIsAFailedDelivery(): void
    {
        // A real page: the receiver answered 500 with a text, so Furgonetka logged the call.
        $page = WebhookLogPage::fromArray(Fixtures::json('webhook/logs-failed'));

        self::assertCount(1, $page);
        $log = $page->logs[0];
        self::assertSame(23398208, $log->id);
        self::assertSame('2026-09-29T12:19:05+02:00', $log->datetimeSend?->format(\DATE_ATOM));
        self::assertSame('1582893168850', $log->packageNo);
        self::assertSame(500, $log->httpCode);
        self::assertSame(TrackingState::Transit, $log->trackingState);
        self::assertSame('transit', $log->trackingStateValue);
        self::assertSame('In transit', $log->trackingStateName);
        self::assertSame(0.154, $log->timeout);
        self::assertSame('receiver broke', $log->webhookResponse, 'What the receiver answered, which is the only clue why it failed.');
    }

    public function testThePageCarriesTheTimeOfTheLastSuccessfulDeliveryAndNothingForTheSuccessfulOnes(): void
    {
        $page = WebhookLogPage::fromArray(Fixtures::json('webhook/logs-failed'));

        self::assertSame('2026-09-29T11:11:05+02:00', $page->lastSuccess?->format(\DATE_ATOM));
        self::assertFalse($page->isNextPage);
        self::assertNull($page->nextCursor());
        self::assertSame(Fixtures::json('webhook/logs-failed'), $page->raw());
    }

    public function testALogEntryWithNothingButAnIdStillReads(): void
    {
        $log = WebhookLog::fromArray(['id' => 1]);

        self::assertSame(1, $log->id);
        self::assertNull($log->httpCode);
        self::assertNull($log->trackingState);
        self::assertNull($log->webhookResponse);
    }

    public function testAnEmptyLogPageHasNoNext(): void
    {
        $page = WebhookLogPage::fromArray(Fixtures::json('webhook/logs-empty'));

        self::assertCount(0, $page);
        self::assertNull($page->lastSuccess);
        self::assertFalse($page->isNextPage);
        self::assertNull($page->nextCursor());
        self::assertSame(Fixtures::json('webhook/logs-empty'), $page->raw());
    }

    public function testThePageKnowsTheEntryToContinueAfter(): void
    {
        // Shape from the specification (SettingsWebhookList), not a captured body.
        $page = WebhookLogPage::fromArray([
            'logs' => [
                ['id' => 3, 'datetimeSend' => '2026-09-29T08:30:00+02:00', 'httpCode' => 500, 'webhookResponse' => null],
                ['id' => 2, 'datetimeSend' => '2026-09-29T08:20:00+02:00', 'httpCode' => 200],
            ],
            'lastSuccess' => '2026-09-29T08:20:00+02:00',
            'isNextPage' => true,
        ]);

        self::assertCount(2, $page);
        self::assertSame(2, $page->nextCursor()?->id, 'The last entry of this page.');
        self::assertSame('2026-09-29T08:20:00+02:00', $page->lastSuccess?->format(\DATE_ATOM));
        self::assertSame([3, 2], array_map(static fn (WebhookLog $l): ?int => $l->id, iterator_to_array($page)));
        self::assertNull($page->logs[0]->webhookResponse);
    }

    public function testTheLastPageHasNoCursorEvenWithEntries(): void
    {
        $page = WebhookLogPage::fromArray(['logs' => [['id' => 1]], 'isNextPage' => false]);

        self::assertNull($page->nextCursor());
        self::assertFalse(WebhookLogPage::fromArray(['logs' => []])->isNextPage, 'A missing flag is false.');
    }
}
