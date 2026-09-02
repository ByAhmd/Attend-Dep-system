<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The five figures on the admin dashboard.
 *
 * "Employees" means accounts with the employee role; administrators are
 * counted in the attendance figures if they record attendance, but they are
 * not employees for the headcount.
 */
final readonly class AttendanceDashboardMetrics
{
    public function __construct(
        private AttendanceCalendar $calendar,
    ) {}

    public function employeesTotal(): int
    {
        return $this->employees()->count();
    }

    public function employeesActive(): int
    {
        return $this->employees()->where('status', UserStatus::Active)->count();
    }

    public function checkedInToday(): int
    {
        return $this->today()->count();
    }

    public function checkedOutToday(): int
    {
        return $this->today()->whereNotNull('check_out_at')->count();
    }

    public function currentlyCheckedIn(): int
    {
        return $this->today()->open()->count();
    }

    /**
     * @return Builder<User>
     */
    private function employees(): Builder
    {
        return User::query()->where('role', UserRole::Employee);
    }

    /**
     * @return Builder<Attendance>
     */
    private function today(): Builder
    {
        return Attendance::query()->forDate($this->calendar->today());
    }
}
