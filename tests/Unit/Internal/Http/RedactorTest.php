<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Http;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Redactor;

final class RedactorTest extends TestCase
{
    // -- Headers -----------------------------------------------------------------

    public function testCredentialHeadersKeepOnlyTheirScheme(): void
    {
        self::assertSame('Bearer [redacted]', Redactor::header('Authorization', 'Bearer abc.def.ghi'));
        self::assertSame('Basic [redacted]', Redactor::header('authorization', 'Basic Zm9vOmJhcg=='));
        self::assertSame('Basic [redacted]', Redactor::header('Proxy-Authorization', 'Basic Zm9vOmJhcg=='));
        self::assertSame('[redacted]', Redactor::header('Authorization', 'no-scheme-at-all'));
    }

    public function testOtherHeadersPassThrough(): void
    {
        self::assertSame('application/json', Redactor::header('Accept', 'application/json'));
        self::assertSame('12', Redactor::header('X-Company-Id', '12'));
    }

    // -- JSON --------------------------------------------------------------------

    public function testJsonMasksTheListedKeysAtAnyDepth(): void
    {
        $body = '{"token_type":"Bearer","data":{"nested":{"refresh_token":"rt-secret"}},"access_token":"at-secret"}';

        $masked = Redactor::json($body, Redactor::RESPONSE_KEYS);

        self::assertSame('{"token_type":"Bearer","data":{"nested":{"refresh_token":"[redacted]"}},"access_token":"[redacted]"}', $masked);
    }

    public function testTheWebhookTokenOfAnAnswerIsMaskedAndTheTokenTypeIsNot(): void
    {
        $body = '{"url":"https://shop.example/hook","format":"json","token":"0123456789abcde","token_type":"Bearer"}';

        self::assertSame(
            '{"url":"https://shop.example/hook","format":"json","token":"[redacted]","token_type":"Bearer"}',
            Redactor::json($body, Redactor::RESPONSE_KEYS),
        );
    }

    public function testTheWebhookTokenAndTheIbanOfARequestAreMasked(): void
    {
        $webhook = '{"webhookUrl":"https://shop.example/hook","webhookToken":"0123456789abcde","webhookFormat":"json"}';
        $entry = '{"name":"Jan","iban":"PL1234567890123","iban_name":"Jan Kowalski","phone":"500200300"}';

        self::assertSame('{"webhookUrl":"https://shop.example/hook","webhookToken":"[redacted]","webhookFormat":"json"}', Redactor::json($webhook, Redactor::REQUEST_KEYS));
        self::assertSame('{"name":"Jan","iban":"[redacted]","iban_name":"Jan Kowalski","phone":"500200300"}', Redactor::json($entry, Redactor::REQUEST_KEYS), 'The account number, not the name of its owner.');
    }

    public function testTheSettingsSaveHidesItsTokenNestedNextToTheRules(): void
    {
        $body = '{"orderNotifications":[{"state":"delivered","webhook":true}],"emailBit":{"shipmentDox":true},"webhookUrl":"https://shop.example/hook","webhookToken":"0123456789abcde","webhookFormat":"xml"}';

        $masked = Redactor::body($body, 'application/vnd.furgonetka.v1+json', Redactor::REQUEST_KEYS);

        self::assertStringNotContainsString('0123456789abcde', $masked);
        self::assertStringContainsString('"webhookToken":"[redacted]"', $masked);
        self::assertStringContainsString('"state":"delivered"', $masked);
    }

    public function testJsonHandlesWhitespaceEscapesAndCase(): void
    {
        $body = "{\n  \"Access_Token\" :  \"has \\\"quotes\\\" and \\\\ backslash\",\n  \"keep\": \"me\"\n}";

        $masked = Redactor::json($body, ['access_token']);

        self::assertStringNotContainsString('quotes', $masked);
        self::assertStringNotContainsString('backslash', $masked);
        self::assertStringContainsString('"Access_Token" :  "[redacted]"', $masked);
        self::assertStringContainsString('"keep": "me"', $masked);
        self::assertNotNull(json_decode($masked), 'Still valid JSON.');
    }

    public function testJsonMasksInATruncatedBody(): void
    {
        // A body cut off by a proxy or a size limit is still covered: masking is textual.
        $masked = Redactor::json('{"a":1,"refresh_token":"abcdef","b":', Redactor::RESPONSE_KEYS);

        self::assertSame('{"a":1,"refresh_token":"[redacted]","b":', $masked);
    }

    public function testJsonLeavesOtherKeysAndNonStringValuesAlone(): void
    {
        $body = '{"code":"carrierValidationError","expires_in":3600,"access_token_hint":"x"}';

        self::assertSame($body, Redactor::json($body, ['access_token']));
    }

    public function testJsonWithoutTheKeysIsReturnedAsIs(): void
    {
        $body = '{"a":1}';

        self::assertSame($body, Redactor::json($body, Redactor::REQUEST_KEYS));
        self::assertSame('', Redactor::json('', Redactor::REQUEST_KEYS));
    }

    public function testJsonHandlesAVeryLongSecretValue(): void
    {
        // A JWT-sized or larger value must not trip a regex backtracking or stack limit.
        $body = '{"access_token":"' . str_repeat('a', 2_000_000) . '","keep":"x"}';

        self::assertSame('{"access_token":"[redacted]","keep":"x"}', Redactor::json($body, ['access_token']));
    }

    // -- Form --------------------------------------------------------------------

    public function testFormMasksTheListedFields(): void
    {
        $masked = Redactor::form('password=a&grant_type=password&client_secret=b&scope=api&refresh_token=c', Redactor::REQUEST_KEYS);

        self::assertSame('password=[redacted]&grant_type=password&client_secret=[redacted]&scope=api&refresh_token=[redacted]', $masked);
    }

    public function testFormOnlyMatchesWholeFieldNames(): void
    {
        // "xcode" and "passcode" are not "code" / "password".
        $body = 'xcode=1&passcode=2&code=3';

        self::assertSame('xcode=1&passcode=2&code=[redacted]', Redactor::form($body, Redactor::REQUEST_KEYS));
    }

    public function testFormWithoutTheKeysIsReturnedAsIs(): void
    {
        self::assertSame('grant_type=client_credentials&scope=api', Redactor::form('grant_type=client_credentials&scope=api', Redactor::REQUEST_KEYS));
    }

    // -- Dispatch by content type ----------------------------------------------------

    public function testBodyPicksTheEncodingFromTheContentType(): void
    {
        self::assertSame('{"password":"[redacted]"}', Redactor::body('{"password":"x"}', 'application/vnd.furgonetka.v1+json', Redactor::REQUEST_KEYS));
        self::assertSame('password=[redacted]', Redactor::body('password=x', 'Application/X-WWW-Form-Urlencoded; charset=UTF-8', Redactor::REQUEST_KEYS));
        self::assertSame('', Redactor::body('', 'application/json', Redactor::REQUEST_KEYS));
    }

    public function testBodyWithAnUnknownContentTypeTriesBoth(): void
    {
        self::assertSame('password=[redacted]', Redactor::body('password=x', '', Redactor::REQUEST_KEYS));
        self::assertSame('{"password":"[redacted]"}', Redactor::body('{"password":"x"}', 'text/plain', Redactor::REQUEST_KEYS));
    }

    public function testBodyWithAnUnknownContentTypeLeavesBinaryDataAlone(): void
    {
        $binary = "\x00\x01\x02\xff\xfe\x80" . str_repeat("\xc3\x28\xa0\xa1", 200) . "\x00";

        self::assertSame($binary, Redactor::body($binary, 'application/octet-stream', Redactor::REQUEST_KEYS));
    }
}
