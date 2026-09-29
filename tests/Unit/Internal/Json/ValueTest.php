<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Json;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Enum\Language;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

final class ValueTest extends TestCase
{
    public function testStringAcceptsNumbersBecauseIdsSwitchTypeBetweenRequestAndResponse(): void
    {
        // Furgonetka takes a package id as a string and sometimes returns it as an integer.
        self::assertSame('90014858123', Value::string(['id' => 90014858123], 'id'));
        self::assertSame('90014858123', Value::string(['id' => '90014858123'], 'id'));
        self::assertSame('1.5', Value::string(['n' => 1.5], 'n'));
    }

    public function testStringRejectsWhatIsNotTextLike(): void
    {
        self::assertNull(Value::string(['a' => ''], 'a'));
        self::assertNull(Value::string(['a' => null], 'a'));
        self::assertNull(Value::string(['a' => true], 'a'));
        self::assertNull(Value::string(['a' => ['x']], 'a'));
        self::assertNull(Value::string([], 'a'));
    }

    public function testInt(): void
    {
        self::assertSame(5, Value::int(['a' => 5], 'a'));
        self::assertSame(5, Value::int(['a' => '5'], 'a'));
        self::assertSame(5, Value::int(['a' => 5.0], 'a'));
        self::assertSame(0, Value::int(['a' => 0], 'a'));
        self::assertNull(Value::int(['a' => 'five'], 'a'));
        self::assertNull(Value::int(['a' => NAN], 'a'));
        self::assertNull(Value::int(['a' => INF], 'a'));
        self::assertNull(Value::int(['a' => null], 'a'));
        self::assertNull(Value::int([], 'a'));
    }

    public function testFloat(): void
    {
        self::assertSame(20.76, Value::float(['a' => 20.76], 'a'));
        self::assertSame(20.76, Value::float(['a' => '20.76'], 'a'));
        self::assertSame(20.0, Value::float(['a' => 20], 'a'));
        self::assertNull(Value::float(['a' => 'cheap'], 'a'));
        self::assertNull(Value::float(['a' => null], 'a'));
    }

    public function testBoolAcceptsFlagsButNotArbitraryNumbers(): void
    {
        self::assertTrue(Value::bool(['a' => true], 'a'));
        self::assertFalse(Value::bool(['a' => false], 'a'));
        self::assertTrue(Value::bool(['a' => 1], 'a'));
        self::assertFalse(Value::bool(['a' => 0], 'a'));
        self::assertTrue(Value::bool(['a' => 'TRUE'], 'a'));
        self::assertTrue(Value::bool(['a' => '1'], 'a'));
        self::assertFalse(Value::bool(['a' => 'no'], 'a'));
        self::assertFalse(Value::bool(['a' => '0'], 'a'));
        // "2" is a state of a tri-state field (delivery_on_time), not a flag.
        self::assertNull(Value::bool(['a' => 2], 'a'));
        self::assertNull(Value::bool(['a' => 'maybe'], 'a'));
        self::assertNull(Value::bool(['a' => null], 'a'));
    }

    public function testDateTimeParsesTheShapesTheApiUses(): void
    {
        $withOffset = Value::dateTime(['a' => '2020-08-25T13:15:01+02:00'], 'a');

        self::assertNotNull($withOffset);
        self::assertSame('2020-08-25T13:15:01+02:00', $withOffset->format('c'));
        self::assertNull(Value::dateTime(['a' => 'not a date'], 'a'));
        self::assertNull(Value::dateTime(['a' => null], 'a'));
        self::assertNull(Value::dateTime([], 'a'));
    }

    public function testObject(): void
    {
        self::assertSame(['x' => 1], Value::object(['a' => ['x' => 1]], 'a'));
        self::assertNull(Value::object(['a' => [1, 2]], 'a'), 'A list is not an object.');
        self::assertNull(Value::object(['a' => 'text'], 'a'));
        self::assertNull(Value::object(['a' => null], 'a'));
        self::assertNull(Value::object([], 'a'));
    }

    public function testAnEmptyArrayIsAnEmptyObjectBecauseThePhpBackendSendsEmptyMapsAsLists(): void
    {
        // json_encode([]) is "[]" in PHP, so a map with no entries reaches us as an empty list.
        self::assertSame([], Value::object(['parcels' => []], 'parcels'));
        self::assertSame([], Value::object(['parcels' => json_decode('{}', true)], 'parcels'));
    }

    public function testObjectList(): void
    {
        $data = ['items' => [['id' => 1], 'stray', ['id' => 2], [5, 6], null, []]];

        self::assertSame([['id' => 1], ['id' => 2], []], Value::objectList($data, 'items'));
        self::assertSame([], Value::objectList(['items' => 'nope'], 'items'));
        self::assertSame([], Value::objectList([], 'items'));
    }

    public function testObjectMapKeepsTheKeysAndSkipsWhatIsNotAnObject(): void
    {
        $map = Value::objectMap(['parcels' => ['1582893168850' => ['a' => 1], 'Z1' => ['b' => 2], 'text' => 'no', 'list' => [1, 2], 'empty' => []]], 'parcels');

        self::assertSame(['1582893168850', 'Z1', 'empty'], array_map(strval(...), array_keys($map)), 'An all-digit key becomes an integer in PHP.');
        self::assertSame(['a' => 1], $map[1582893168850]);
        self::assertSame([], $map['empty']);
        self::assertSame([], Value::objectMap([], 'parcels'));
        self::assertSame([], Value::objectMap(['parcels' => []], 'parcels'), 'PHP writes an empty map as [].');
        self::assertSame([], Value::objectMap(['parcels' => [['a' => 1]]], 'parcels'), 'A list is not a map.');
        self::assertSame([], Value::objectMap(['parcels' => 'x'], 'parcels'));
    }

    public function testStringList(): void
    {
        self::assertSame(['a', 'b'], Value::stringList(['l' => ['a', 'b']], 'l'));
        self::assertSame(['a', '2', '1.5'], Value::stringList(['l' => ['a', 2, 1.5, null, ['x'], true]], 'l'));
        self::assertSame(['single'], Value::stringList(['l' => 'single'], 'l'));
        self::assertSame([], Value::stringList(['l' => 5], 'l'));
        self::assertSame([], Value::stringList([], 'l'));
    }

    public function testEnumIsNullForAnUnknownMemberInsteadOfAnError(): void
    {
        self::assertSame(Language::Polish, Value::enum(['l' => 'pl_PL'], 'l', Language::class));
        // A value added by Furgonetka after this release must not break the client.
        self::assertNull(Value::enum(['l' => 'de_DE'], 'l', Language::class));
        self::assertNull(Value::enum([], 'l', Language::class));
    }
}
