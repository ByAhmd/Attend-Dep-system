<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\Weekday;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which days anybody is expected at the company at all.
 *
 * A day is a working day unless it is one of the configured weekend days
 * or inside an official holiday. Every screen that reports somebody
 * missing asks here first, because "did not check in" is only a fact
 * worth printing on a day somebody was expected - on a Friday it is an
 * accusation about nothing.
 *
 * The calendar says who was EXPECTED, never who may record: check-in and
 * check-out work on any day of the year, and an employee who comes in on
 * a holiday records a perfectly ordinary session.
 */
final readonly class WorkingCalendar
{
    public function isWorkingDay(CarbonInterface $day): bool
    {
        return ! $this->isWeekend($day) && ! $this->holidayCovering($day) instanceof Holiday;
    }

    public function isWeekend(CarbonInterface $day): bool
    {
        return in_array(Weekday::ofDate($day)->value, AttendanceSetting::current()->weekend_days, strict: true);
    }

    /**
     * The holiday the given day falls inside, if any. Where two overlap -
     * a data-entry mistake the table does not forbid - the earlier one
     * answers, deterministically.
     */
    public function holidayCovering(CarbonInterface $day): ?Holiday
    {
        return Holiday::query()
            ->overlapping($day, $day)
            ->inDateOrder()
            ->first();
    }

    /**
     * How many working days the inclusive range holds.
     */
    public function workingDaysBetween(CarbonInterface $from, CarbonInterface $until): int
    {
        return count($this->workingDays($from, $until));
    }

    /**
     * The working days of the inclusive range, in order.
     *
     * One query for the holidays and one read of the settings, then plain
     * counting: the ranges this product asks about are a leave request (at
     * most 90 days) or a month, so a loop over the days is cheaper to be
     * sure of than arithmetic over three overlapping intervals.
     *
     * @return list<CarbonImmutable>
     */
    public function workingDays(CarbonInterface $from, CarbonInterface $until): array
    {
        $first = CarbonImmutable::instance($from)->startOfDay();
        $last = CarbonImmutable::instance($until)->startOfDay();

        if ($last->lessThan($first)) {
            return [];
        }

        $weekend = AttendanceSetting::current()->weekend_days;
        $holidays = Holiday::query()->overlapping($first, $last)->get();

        $days = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (in_array(Weekday::ofDate($day)->value, $weekend, strict: true)) {
                continue;
            }

            if ($holidays->contains(static fn (Holiday $holiday): bool => $holiday->coversDate($day))) {
                continue;
            }

            $days[] = $day;
        }

        return $days;
    }
}
