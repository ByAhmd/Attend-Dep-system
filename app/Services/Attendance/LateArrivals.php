<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who arrived late today.
 *
 * Lateness belongs to the DAY, not to the row: a day's verdict is made by
 * its first check-in, and someone back from lunch at 13:00 has not arrived
 * late twice. The query therefore keeps only each employee's first session
 * of today, and of those only the ones whose check-in is strictly after
 * the late threshold - the start of the working day plus the grace.
 *
 * Derived, never stored, like the session status: the rows already carry
 * the only fact that matters (when the first check-in happened), and a
 * verdict column would have to be rewritten every time a correction moved
 * a morning. The cost of deriving it is one indexed scan of today's rows,
 * on a dashboard read by a handful of people.
 */
final readonly class LateArrivals
{
    public function __construct(
        private AttendanceCalendar $calendar,
    ) {}

    /**
     * Today's late arrivals, one row per person: the first session of each
     * employee's day, kept only when it began after the late threshold,
     * earliest first. Corrected check-ins count at their corrected moment,
     * because check_in_at is the effective moment everywhere.
     *
     * @return Builder<Attendance>
     */
    public function queryForToday(): Builder
    {
        $today = $this->calendar->today();
        $threshold = AttendanceSetting::current()->workingHours()->lateThresholdOn($today);

        return Attendance::query()
            ->forDate($today)
            ->where('check_in_at', '>', $threshold->format('Y-m-d H:i:s'))
            // "First of the day": no session of the same employee-day began
            // before this one. The id breaks the tie of two check-ins on
            // the same second, so even that day has exactly one first.
            ->whereNotExists(function (QueryBuilder $query) use ($today): void {
                $query->select(DB::raw(1))
                    ->from('attendances as earlier')
                    ->whereColumn('earlier.user_id', 'attendances.user_id')
                    ->where('earlier.attendance_date', $today->toDateString())
                    ->where(function (QueryBuilder $tie): void {
                        $tie->whereColumn('earlier.check_in_at', '<', 'attendances.check_in_at')
                            ->orWhere(function (QueryBuilder $sameSecond): void {
                                $sameSecond->whereColumn('earlier.check_in_at', 'attendances.check_in_at')
                                    ->whereColumn('earlier.id', '<', 'attendances.id');
                            });
                    });
            })
            ->with('user')
            ->orderBy('check_in_at')
            ->orderBy('id');
    }
}
