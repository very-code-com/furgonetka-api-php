<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Json;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Internal\Json\DateFormat;

final class DateFormatTest extends TestCase
{
    public function testDateAcceptsStringsAndDateObjects(): void
    {
        self::assertSame('2026-10-05', DateFormat::date('2026-10-05'));
        self::assertSame('2026-10-05', DateFormat::date(new \DateTimeImmutable('2026-10-05 14:30:00')));
        self::assertSame('2026-10-05', DateFormat::date(new \DateTime('2026-10-05')));
        self::assertNull(DateFormat::date(null));
        self::assertNull(DateFormat::date(''));
    }

    public function testParseKeepsTheOffsetOfATimestampThatHasOne(): void
    {
        // The shape used by tracking, commands and regulations in Furgonetka's documentation.
        $date = DateFormat::parse('2020-08-25T13:15:01+02:00');

        self::assertNotNull($date);
        self::assertSame('2020-08-25T13:15:01+02:00', $date->format('c'));
        self::assertSame(7200, $date->getOffset());
    }

    public function testParseReadsANaiveTimestampInPolishTime(): void
    {
        // Independent of the host's date.timezone: the same text always gives the same instant.
        $original = date_default_timezone_get();

        try {
            foreach (['UTC', 'America/New_York'] as $hostZone) {
                date_default_timezone_set($hostZone);

                $summer = DateFormat::parse('2021-07-01 12:00:00');
                $winter = DateFormat::parse('2021-01-15 12:00:00');

                self::assertNotNull($summer);
                self::assertNotNull($winter);
                self::assertSame('2021-07-01T12:00:00+02:00', $summer->format('c'), $hostZone);
                self::assertSame('2021-01-15T12:00:00+01:00', $winter->format('c'), $hostZone);
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testParseReadsADayWithoutATime(): void
    {
        $date = DateFormat::parse('2023-05-25');

        self::assertNotNull($date);
        self::assertSame('2023-05-25', $date->format('Y-m-d'));
    }

    public function testParseReturnsNullForWhatIsNotADate(): void
    {
        self::assertNull(DateFormat::parse(null));
        self::assertNull(DateFormat::parse(''));
        self::assertNull(DateFormat::parse('not a date at all'));
        self::assertNull(DateFormat::parse('2026-13-45 99:99:99'));
    }
}
