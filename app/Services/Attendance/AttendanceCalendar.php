<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * The official clock and calendar of attendance.
 *
 * The attendance period is one calendar day in the application timezone
 * (Asia/Riyadh). Every timestamp and every "today" in the system comes from
 * here and never from the employee's device, whose clock and timezone are
 * both under the employee's control. Tests move this clock with
 * Carbon::setTestNow().
 */
final readonly class AttendanceCalendar
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /**
     * The first instant of the current calendar month, in the application
     * timezone.
     *
     * Gregorian, because that is Carbon's calendar and therefore what the
     * interface has to say out loud in Arabic: a reader told "the allowance
     * resets each month" would otherwise reasonably assume the Hijri one,
     * and be counting a different thirty days from the system.
     */
    public function monthStart(): CarbonImmutable
    {
        return $this->now()->startOfMonth();
    }
}
