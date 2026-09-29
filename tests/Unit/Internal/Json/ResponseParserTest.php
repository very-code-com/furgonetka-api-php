<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Json;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Internal\Json\ResponseParser;

final class ResponseParserTest extends TestCase
{
    private const SECRET = 'body-secret-token-77d1';

    public function testDecodeReturnsTheObject(): void
    {
        $decoded = (new ResponseParser())->decode('{"id":"1","parcels":[{"weight":5.5}],"nested":{"a":null}}', 'fetchPackage');

        self::assertSame(['id' => '1', 'parcels' => [['weight' => 5.5]], 'nested' => ['a' => null]], $decoded);
    }

    public function testSurroundingWhitespaceIsFine(): void
    {
        self::assertSame(['a' => 1], (new ResponseParser())->decode("  \n{\"a\":1}\r\n", 'op'));
    }

    public function testAnEmptyObjectIsAnObject(): void
    {
        // "{}" decodes to an empty PHP array, exactly like "[]"; only the first is an object.
        self::assertSame([], (new ResponseParser())->decode('{}', 'op'));
        self::assertSame([], (new ResponseParser())->decode(' {} ', 'op'));
    }

    public function testAnEmptyListIsNotAnObject(): void
    {
        $this->expectException(FurgonetkaApiResponseParseException::class);
        $this->expectExceptionMessage('not an object for listPackages');

        (new ResponseParser())->decode('[]', 'listPackages');
    }

    public function testIntegersBeyondPhpRangeStayExact(): void
    {
        // A long identifier must not degrade into a float and lose digits.
        $decoded = (new ResponseParser())->decode('{"id":123456789012345678901234567890,"small":42}', 'op');

        self::assertSame('123456789012345678901234567890', $decoded['id']);
        self::assertSame(42, $decoded['small']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'empty' => ['', 'empty body for op'];
        yield 'blank' => ["  \n ", 'empty body for op'];
        yield 'malformed' => ['{"a":', 'malformed JSON for op'];
        yield 'html' => ['<html lang="en"><body>502 Bad Gateway</body></html>', 'malformed JSON for op'];
        yield 'list' => ['[1,2,3]', 'not an object for op'];
        yield 'list of objects' => ['[{"a":1}]', 'not an object for op'];
        yield 'string' => ['"text"', 'not an object for op'];
        yield 'number' => ['42', 'not an object for op'];
        yield 'null' => ['null', 'not an object for op'];
        yield 'true' => ['true', 'not an object for op'];
    }

    #[DataProvider('unusableBodies')]
    public function testAnUnusableBodyBecomesAParseException(string $body, string $message): void
    {
        try {
            (new ResponseParser())->decode($body, 'op');
            self::fail('Expected a parse exception.');
        } catch (FurgonetkaApiResponseParseException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testAMalformedBodyReportsTheJsonErrorInTheMessageButNotAsTheCause(): void
    {
        try {
            (new ResponseParser())->decode('{"a":', 'op');
            self::fail('Expected a parse exception.');
        } catch (FurgonetkaApiResponseParseException $e) {
            self::assertStringContainsString('malformed JSON for op: Syntax error', $e->getMessage());
            // A chained JsonException would carry the whole body in the arguments of json_decode().
            self::assertNull($e->getPrevious());
        }
    }

    public function testExceptionsNeverCarryTheBody(): void
    {
        // The client attaches a redacted body in debug mode only; the parser must not do it
        // for itself, and its messages must not quote the content.
        foreach ([
            '{"access_token":"' . self::SECRET . '"',
            '["' . self::SECRET . '"]',
            '"' . self::SECRET . '"',
        ] as $body) {
            try {
                (new ResponseParser())->decode($body, 'op');
                self::fail('Expected a parse exception.');
            } catch (FurgonetkaApiResponseParseException $e) {
                self::assertNull($e->getRawResponse());
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
                self::assertStringNotContainsString(self::SECRET, $e->getDebugReport());
                // Arguments are kept whole in getTrace(), which is what error trackers read.
                self::assertStringNotContainsString(self::SECRET, print_r($e->getTrace(), true));
            }
        }
    }
}
