<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending correction request for today, asking for a whole working day
 * and naming no session.
 *
 * Every value is deterministic: a test that asserts what a queue shows, or
 * what an approval writes into attendances, has to know the times it is
 * asserting. submitted_at comes from AttendanceCalendar rather than from
 * now(), so a frozen Riyadh clock moves the request with it and the quota's
 * month boundary can be exercised.
 *
 * @extends Factory<AttendanceCorrection>
 */
final class AttendanceCorrectionFactory extends Factory
{
    public const string CHECK_IN_TIME = '08:00';

    public const string CHECK_OUT_TIME = '17:00';

    public function definition(): array
    {
        $calendar = app(AttendanceCalendar::class);

        return [
            'user_id' => User::factory(),
            'attendance_id' => null,
            'attendance_date' => $calendar->today()->toDateString(),
            'reason' => CorrectionReason::ForgotToRecord,
            'requested_check_in_time' => self::CHECK_IN_TIME,
            'requested_check_out_time' => self::CHECK_OUT_TIME,
            'note' => null,
            'status' => RequestStatus::Pending,
            'submitted_at' => $calendar->now(),
        ];
    }

    /**
     * The request as an administrator left it. The decider and the moment
     * go together because the table refuses a decision missing either.
     */
    public function approvedBy(User $admin, ?string $note = null): static
    {
        return $this->decidedBy(RequestStatus::Approved, $admin, $note);
    }

    public function rejectedBy(User $admin, ?string $note = null): static
    {
        return $this->decidedBy(RequestStatus::Rejected, $admin, $note);
    }

    /**
     * Point the request at an existing session, and at the day that session
     * belongs to - the two can never disagree, because the service refuses
     * a request naming a session from another day.
     */
    public function forSession(Attendance $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendance_id' => $session->id,
            'user_id' => $session->user_id,
            'attendance_date' => $session->attendance_date->toDateString(),
        ]);
    }

    public function on(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendance_date' => $date->toDateString(),
        ]);
    }

    private function decidedBy(RequestStatus $status, User $admin, ?string $note): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'decided_by_id' => $admin->id,
            'decided_at' => app(AttendanceCalendar::class)->now(),
            'decision_note' => $note,
        ]);
    }
}
