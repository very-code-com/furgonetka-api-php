<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The opening hours of a point across a week.
 *
 * A day that is null is a day the point is closed: Furgonetka sends `"sunday": null` for it. A
 * parcel locker that is open around the clock is a point with `opening_hours` null, not a day.
 */
final class OpeningHours
{
    /**
     * @param OpeningHoursDay|null $monday    Monday.
     * @param OpeningHoursDay|null $tuesday   Tuesday.
     * @param OpeningHoursDay|null $wednesday Wednesday.
     * @param OpeningHoursDay|null $thursday  Thursday.
     * @param OpeningHoursDay|null $friday    Friday.
     * @param OpeningHoursDay|null $saturday  Saturday.
     * @param OpeningHoursDay|null $sunday    Sunday.
     */
    public function __construct(
        public readonly ?OpeningHoursDay $monday = null,
        public readonly ?OpeningHoursDay $tuesday = null,
        public readonly ?OpeningHoursDay $wednesday = null,
        public readonly ?OpeningHoursDay $thursday = null,
        public readonly ?OpeningHoursDay $friday = null,
        public readonly ?OpeningHoursDay $saturday = null,
        public readonly ?OpeningHoursDay $sunday = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $day = static function (string $name) use ($data): ?OpeningHoursDay {
            $hours = Value::object($data, $name);

            return $hours !== null ? OpeningHoursDay::fromArray($hours) : null;
        };

        return new self(
            $day('monday'),
            $day('tuesday'),
            $day('wednesday'),
            $day('thursday'),
            $day('friday'),
            $day('saturday'),
            $day('sunday'),
        );
    }

    /**
     * The seven days by name, Monday first; a closed day is null.
     *
     * @return array<string, OpeningHoursDay|null>
     */
    public function days(): array
    {
        return [
            'monday' => $this->monday,
            'tuesday' => $this->tuesday,
            'wednesday' => $this->wednesday,
            'thursday' => $this->thursday,
            'friday' => $this->friday,
            'saturday' => $this->saturday,
            'sunday' => $this->sunday,
        ];
    }
}
