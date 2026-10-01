<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\WorkingCalendar;
use Carbon\CarbonImmutable;

/**
 * One employee's annual leave balance, derived and never stored.
 *
 * The entitlement is the company figure in the settings unless the
 * account carries its own override. What is used is the APPROVED annual
 * leave of the current Gregorian year, counted in WORKING days: deducting
 * a Friday or an Eid from somebody's allowance would charge them for a
 * day nobody was expected anyway. Only the Annual type draws on the
 * balance - sick, exam, bereavement and unpaid leave are their own
 * agreements - and an exit and return is hours, not a day.
 *
 * The balance INFORMS, it never refuses: it is printed where a request
 * is written and where one is decided, and the decision stays a person's.
 * It may therefore go negative, and when it has, the screens say so
 * rather than clamping the number - an over-granted allowance is a fact
 * the owner should be able to read.
 *
 * Derived like the correction quota: nothing to reset, no stored counter
 * and no cron. A request spanning two years draws on each year the days
 * that fall inside it.
 */
final readonly class LeaveBalance
{
    public function __construct(
        private AttendanceCalendar $calendar,
        private WorkingCalendar $workingCalendar,
    ) {}

    public function entitlementFor(User $employee): int
    {
        return $employee->annual_leave_override ?? AttendanceSetting::current()->annual_leave_days;
    }

    /**
     * Working days of approved annual leave falling inside the current
     * Gregorian year.
     */
    public function usedThisYear(User $employee): int
    {
        [$yearStart, $yearEnd] = $this->currentYear();

        $requests = LeaveRequest::query()
            ->forUser($employee)
            ->where('status', RequestStatus::Approved)
            ->where('type', LeaveType::Annual)
            ->where('is_exit_and_return', false)
            ->overlappingRange($yearStart, $yearEnd)
            ->get();

        $used = 0;

        foreach ($requests as $request) {
            $used += $this->workingCalendar->workingDaysBetween(
                $request->starts_on->max($yearStart),
                $request->ends_on->min($yearEnd),
            );
        }

        return $used;
    }

    /**
     * What is left of the year's allowance; negative when more was
     * approved than the allowance held.
     */
    public function remainingFor(User $employee): int
    {
        return $this->entitlementFor($employee) - $this->usedThisYear($employee);
    }

    /**
     * What one request would draw on the balance: the working days of its
     * whole range. Printed beside a pending request so the person deciding
     * sees the price next to the purse.
     */
    public function costOf(LeaveRequest $request): int
    {
        if ($request->is_exit_and_return) {
            return 0;
        }

        return $this->workingCalendar->workingDaysBetween($request->starts_on, $request->ends_on);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function currentYear(): array
    {
        $now = $this->calendar->now();

        return [$now->startOfYear(), $now->endOfYear()->startOfDay()];
    }
}
