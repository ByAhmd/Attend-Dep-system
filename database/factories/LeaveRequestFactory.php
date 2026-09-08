<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending annual leave request covering today and the two days after it.
 *
 * Three days rather than one, so that dayCount(), the period column and the
 * overlap rules all have something to be wrong about. The reason is a real
 * sentence because the table refuses an empty one.
 *
 * @extends Factory<LeaveRequest>
 */
final class LeaveRequestFactory extends Factory
{
    public const int DEFAULT_DAYS = 3;

    public const string REASON = 'ظرف عائلي يستدعي السفر.';

    public function definition(): array
    {
        $calendar = app(AttendanceCalendar::class);
        $today = $calendar->today();

        return [
            'user_id' => User::factory(),
            'type' => LeaveType::Annual,
            'starts_on' => $today->toDateString(),
            'ends_on' => $today->addDays(self::DEFAULT_DAYS - 1)->toDateString(),
            'is_exit_and_return' => false,
            'reason' => self::REASON,
            'status' => RequestStatus::Pending,
            'submitted_at' => $calendar->now(),
        ];
    }

    public function approvedBy(User $admin, ?string $note = null): static
    {
        return $this->decidedBy(RequestStatus::Approved, $admin, $note);
    }

    public function rejectedBy(User $admin, ?string $note = null): static
    {
        return $this->decidedBy(RequestStatus::Rejected, $admin, $note);
    }

    public function ofType(LeaveType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    /**
     * Both ends inclusive, exactly as the employee reads them.
     */
    public function between(CarbonInterface $from, CarbonInterface $until): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_on' => $from->toDateString(),
            'ends_on' => $until->toDateString(),
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
