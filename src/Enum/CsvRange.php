<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Which shipments a CSV export covers (`range` of `PUT /csv-command/{uuid}`).
 *
 * `Selected` needs the shipments named in `packages`; every other value is a period of the sent
 * shipments.
 */
enum CsvRange: string
{
    /** Today. */
    case Today = 'today';

    /** Yesterday. */
    case Yesterday = 'yesterday';

    /** The last week. */
    case LastWeek = 'last_week';

    /** The last 30 days. */
    case Last30Days = 'last_30_days';

    /** The month before this one. */
    case MonthBefore = 'month_before';

    /** Two months before. */
    case TwoMonthsBefore = '2_months_before';

    /** Three months before. */
    case ThreeMonthsBefore = '3_months_before';

    /** Four months before. */
    case FourMonthsBefore = '4_months_before';

    /** Five months before. */
    case FiveMonthsBefore = '5_months_before';

    /** The shipments you name, and only those. */
    case Selected = 'selected';
}
