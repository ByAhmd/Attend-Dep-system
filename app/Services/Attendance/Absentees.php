<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who was expected today and has not checked in.
 *
 * Three exclusions make the sentence honest. On a weekend or a holiday
 * nobody was expected, so the list is empty by construction rather than
 * full of accusations about a Friday. An employee on approved leave was
 * excused in writing, so they are not listed - though an approved exit
 * and return is not a day off and excuses nobody. And only active
 * employee accounts are expected at all: administrators record attendance
 * when they come in, but they are not on the roster this list reads, for
 * the same reason the dashboard's headcount does not count them.
 *
 * "Has not checked in" is the whole claim. It is a statement about the
 * absence of a record, not about where anybody is, and the screen that
 * prints it must say no more than that.
 */
final readonly class Absentees
{
    public function __construct(
        private AttendanceCalendar $calendar,
        private WorkingCalendar $workingCalendar,
    ) {}

    /**
     * Today's expected-but-unrecorded employees, alphabetically. Empty on
     * a day nobody was expected.
     *
     * @return Builder<User>
     */
    public function queryForToday(): Builder
    {
        $today = $this->calendar->today();

        if (! $this->workingCalendar->isWorkingDay($today)) {
            // The emptiness is the answer, and it must not depend on what
            // the table holds.
            return User::query()->whereRaw('1 = 0');
        }

        $day = $today->toDateString();

        return User::query()
            ->where('role', UserRole::Employee)
            ->where('status', UserStatus::Active)
            ->whereNotExists(function (QueryBuilder $query) use ($day): void {
                $query->select(DB::raw(1))
                    ->from('attendances')
                    ->whereColumn('attendances.user_id', 'users.id')
                    ->where('attendances.attendance_date', $day);
            })
            ->whereNotExists(function (QueryBuilder $query) use ($day): void {
                $query->select(DB::raw(1))
                    ->from('leave_requests')
                    ->whereColumn('leave_requests.user_id', 'users.id')
                    ->where('leave_requests.status', RequestStatus::Approved->value)
                    ->where('leave_requests.is_exit_and_return', false)
                    ->where('leave_requests.starts_on', '<=', $day)
                    ->where('leave_requests.ends_on', '>=', $day);
            })
            ->orderBy('name');
    }
}
