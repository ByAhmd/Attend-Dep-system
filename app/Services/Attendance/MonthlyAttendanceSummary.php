<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Data\Attendance\MonthlyEmployeeSummary;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\WorkingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One month, summed per employee: what the attendance list already holds,
 * folded into the figures somebody doing payroll elsewhere actually
 * copies out.
 *
 * Nothing here is new information. Days attended, time inside, late days,
 * early departures and approved leave are all restatements of stored
 * rows; the one derived figure - unrecorded days - exists only because
 * the working calendar first says which of the month's days anybody was
 * expected at all, and it counts only days that have already passed.
 * Lateness is judged against the working day currently in force, exactly
 * as the attendance list and the dashboard judge it, so the three screens
 * can never disagree.
 *
 * The roster is the non-deleted employee accounts, whatever their status:
 * an account deactivated mid-month still worked its days, and a report
 * that dropped them would subtract a person from a month that happened.
 * Administrators are not on it, for the same reason the dashboard's
 * headcount does not count them.
 */
final readonly class MonthlyAttendanceSummary
{
    public function __construct(
        private AttendanceCalendar $calendar,
        private WorkingCalendar $workingCalendar,
    ) {}

    /**
     * The month's working days that have already passed, today included.
     * The report may be asked about a future month; the answer is no days
     * and therefore no figures, not a forecast.
     *
     * @return list<CarbonImmutable>
     */
    public function elapsedWorkingDays(CarbonImmutable $monthStart): array
    {
        $firstDay = $monthStart->startOfMonth();
        $lastDay = $firstDay->endOfMonth()->startOfDay()->min($this->calendar->today());

        return $this->workingCalendar->workingDays($firstDay, $lastDay);
    }

    /**
     * Every employee's month, alphabetically.
     *
     * @return list<MonthlyEmployeeSummary>
     */
    public function rows(CarbonImmutable $monthStart): array
    {
        $firstDay = $monthStart->startOfMonth();
        $lastDay = $firstDay->endOfMonth()->startOfDay();
        $workingHours = AttendanceSetting::current()->workingHours();
        $workingDays = $this->elapsedWorkingDays($monthStart);

        $employees = User::query()
            ->where('role', UserRole::Employee)
            ->with('jobTitle')
            ->orderBy('name')
            ->get();

        $sessions = Attendance::query()
            ->whereIn('user_id', $employees->modelKeys())
            ->where('attendance_date', '>=', $firstDay->toDateString())
            ->where('attendance_date', '<=', $lastDay->toDateString())
            ->get()
            ->groupBy('user_id');

        $leaves = LeaveRequest::query()
            ->whereIn('user_id', $employees->modelKeys())
            ->where('status', RequestStatus::Approved)
            ->where('is_exit_and_return', false)
            ->overlappingRange($firstDay, $lastDay)
            ->get()
            ->groupBy('user_id');

        $rows = [];

        foreach ($employees as $employee) {
            $rows[] = $this->summarise(
                $employee,
                $sessions->get($employee->id) ?? new Collection,
                $leaves->get($employee->id) ?? new Collection,
                $workingDays,
                $workingHours,
            );
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Attendance>  $sessions
     * @param  Collection<int, LeaveRequest>  $leaves
     * @param  list<CarbonImmutable>  $workingDays
     */
    private function summarise(
        User $employee,
        Collection $sessions,
        Collection $leaves,
        array $workingDays,
        WorkingHours $workingHours,
    ): MonthlyEmployeeSummary {
        /** @var Collection<string, Collection<int, Attendance>> $byDate */
        $byDate = $sessions->groupBy(static fn (Attendance $session): string => $session->attendance_date->toDateString());

        $secondsInside = (int) $sessions->sum(static fn (Attendance $session): int => $session->durationInSeconds() ?? 0);

        $earlyCheckOuts = $sessions->filter(static fn (Attendance $session): bool => $session->leftEarly())->count();

        // The day's verdict is its first check-in's, exactly as the
        // dashboard and the attendance list decide it.
        $lateDays = 0;
        $latenessSeconds = 0;

        foreach ($byDate as $daySessions) {
            $first = $daySessions->sortBy([['check_in_at', 'asc'], ['id', 'asc']])->first();

            if ($first instanceof Attendance && $workingHours->isLateArrival($first->check_in_at)) {
                $lateDays++;
                $latenessSeconds += $workingHours->latenessSeconds($first->check_in_at);
            }
        }

        // Each elapsed working day is exactly one thing: attended, on
        // approved leave, or unrecorded - in that order, because a session
        // is the stronger fact than the agreement beside it.
        $leaveDays = 0;
        $daysUnrecorded = 0;

        foreach ($workingDays as $day) {
            if ($byDate->has($day->toDateString())) {
                continue;
            }

            if ($leaves->contains(static fn (LeaveRequest $leave): bool => $leave->coversDate($day))) {
                $leaveDays++;

                continue;
            }

            $daysUnrecorded++;
        }

        return new MonthlyEmployeeSummary(
            employeeName: $employee->name,
            jobTitle: $employee->jobTitle?->displayName(),
            daysAttended: $byDate->count(),
            secondsInside: $secondsInside,
            lateDays: $lateDays,
            latenessSeconds: $latenessSeconds,
            earlyCheckOuts: $earlyCheckOuts,
            leaveDays: $leaveDays,
            daysUnrecorded: $daysUnrecorded,
        );
    }
}
