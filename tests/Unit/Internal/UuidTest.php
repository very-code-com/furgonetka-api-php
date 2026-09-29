<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;

final class UuidTest extends TestCase
{
    public function testAGeneratedUuidIsVersion4InCanonicalForm(): void
    {
        $uuid = Uuid::v4();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        self::assertTrue(Uuid::isValid($uuid));
    }

    public function testTheyDoNotRepeat(): void
    {
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            $seen[Uuid::v4()] = true;
        }

        self::assertCount(500, $seen);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'the one in the documentation' => ['8b881c52-6473-467a-a7c9-d0aa4c4f3bec', true];
        yield 'upper case' => ['8B881C52-6473-467A-A7C9-D0AA4C4F3BEC', true];
        yield 'a version 1 UUID' => ['6ba7b810-9dad-11d1-80b4-00c04fd430c8', true];
        yield 'without dashes' => ['8b881c5264737467a7c9d0aa4c4f3bec', false];
        yield 'too short' => ['8b881c52-6473-467a-a7c9-d0aa4c4f3be', false];
        yield 'a non-hex character' => ['8b881c52-6473-467a-a7c9-d0aa4c4f3bez', false];
        yield 'empty' => ['', false];
        yield 'with a trailing newline' => ["8b881c52-6473-467a-a7c9-d0aa4c4f3bec\n", false];
        yield 'not a UUID at all' => ['not-a-uuid', false];
    }

    #[DataProvider('candidates')]
    public function testValidity(string $value, bool $valid): void
    {
        self::assertSame($valid, Uuid::isValid($value));
    }
}
