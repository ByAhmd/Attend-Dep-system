<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceSetting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * How many correction requests one employee has left this month.
 *
 * Three things about this figure are decisions rather than details:
 *
 * The count is over submitted_at and never over created_at. timestamps()
 * produces MySQL TIMESTAMP columns and config/database.php sets no session
 * timezone, so created_at is read back differently on the MySQL 8
 * development box and the MariaDB 10.4 host. submitted_at is a DATETIME the
 * server wrote from AttendanceCalendar, and it means the same thing in both
 * places.
 *
 * It counts submissions and not approvals. A rejected request still spends
 * the allowance; otherwise an employee sends fifty and the administrator
 * becomes the rationing mechanism that the allowance exists to spare them
 * from being.
 *
 * There is nothing to reset. "The allowance renews each month" describes a
 * COUNT over a date range, not a counter somebody has to zero: this host has
 * no cron, needs none, and a stored counter would be a number that can be
 * wrong.
 */
final readonly class CorrectionQuota
{
    public function __construct(private AttendanceCalendar $calendar) {}

    /**
     * The configured allowance. Zero means correction requests are switched
     * off entirely, which is a real setting and not an absent one.
     */
    public function allowance(): int
    {
        return AttendanceSetting::current()->correction_requests_per_month;
    }

    /**
     * Requests this employee submitted since the first instant of the
     * current Riyadh month, whatever became of them.
     */
    public function usedThisMonth(User $employee): int
    {
        return AttendanceCorrection::query()
            ->forUser($employee)
            ->where('submitted_at', '>=', $this->calendar->monthStart()->toDateTimeString())
            ->count();
    }

    public function remainingFor(User $employee): int
    {
        return max($this->allowance() - $this->usedThisMonth($employee), 0);
    }

    /**
     * The first instant of next month - the date the employee is told the
     * allowance returns on, so the sentence and the COUNT agree about which
     * calendar they mean.
     */
    public function resetsOn(): CarbonImmutable
    {
        return $this->calendar->monthStart()->addMonth();
    }
}
