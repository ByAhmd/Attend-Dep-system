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
 *
 * Every attendance figure counts PEOPLE, not rows. A row is one session and
 * an employee may leave and come back several times a day, so counting rows
 * would report three people present because one person went out for lunch.
 * The three attendance figures therefore all count distinct accounts, and
 * they overlap on purpose: someone who left at noon and came back is both
 * "checked out today" (a completed session) and "currently checked in" (an
 * open one).
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

    /**
     * People who recorded at least one session today.
     */
    public function checkedInToday(): int
    {
        return $this->peopleWithSessions($this->today());
    }

    /**
     * People whose day holds at least one completed session - they came and
     * they left, whether or not they have since come back.
     */
    public function checkedOutToday(): int
    {
        return $this->peopleWithSessions($this->today()->whereNotNull('check_out_at'));
    }

    /**
     * People inside the company right now: an open session dated today.
     * Yesterday's forgotten check-out is a missing check-out, not somebody
     * who has been at their desk for eighteen hours.
     */
    public function currentlyCheckedIn(): int
    {
        return $this->peopleWithSessions($this->today()->open());
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

    /**
     * How many distinct accounts the given sessions belong to.
     *
     * @param  Builder<Attendance>  $sessions
     */
    private function peopleWithSessions(Builder $sessions): int
    {
        return $sessions->distinct()->count('user_id');
    }
}
