<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Json;

/**
 * Date handling on both sides of the wire.
 *
 * Requests: the only date Furgonetka takes as input is a plain day (`pickup_date.date`,
 * `delivery_on_day`), as `YYYY-MM-DD`. Callers may pass such a string or any `DateTimeInterface`.
 *
 * Responses: Furgonetka mixes three shapes, sometimes within one resource:
 *  - ISO 8601 with an offset, `2020-08-25T13:15:01+02:00` (tracking, commands, regulations);
 *  - a naive date-time, `2021-07-01 12:00:00`;
 *  - a plain day, `2023-05-25`.
 *
 * @internal
 */
final class DateFormat
{
    /** `YYYY-MM-DD`, the shape Furgonetka expects for day-only fields. */
    public const DATE = 'Y-m-d';

    /**
     * Zone assumed for a timestamp that carries no offset. Every offset in Furgonetka's examples
     * is Polish time (+01:00/+02:00), and reading a naive value in the host's default zone would
     * make the result depend on `date.timezone`. This is an assumption about the naive shape,
     * which the sandbox has not confirmed yet (open check W18 in the plan).
     */
    public const NAIVE_ZONE = 'Europe/Warsaw';

    /** Normalise a day-only input; strings are passed through untouched. */
    public static function date(string|\DateTimeInterface|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        return $value->format(self::DATE);
    }

    /**
     * Parse any of the response shapes above. A value with an offset keeps it; one without is
     * read in {@see self::NAIVE_ZONE}. Returns null when the text is not a date, because an
     * unparseable timestamp is not worth failing a whole response over.
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(self::NAIVE_ZONE));
        } catch (\Exception) {
            return null;
        }
    }
}
