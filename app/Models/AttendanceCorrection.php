<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AttendanceCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's request to change a recorded attendance time, and the
 * decision on it.
 *
 * The two requested moments are wall-clock times beside the date the
 * request names, never timestamps: the phone knows what o'clock the
 * employee means and nothing else, and the server composes the moment in
 * Asia/Riyadh when an administrator approves it.
 *
 * #[Fillable] lists ONLY what the employee states. user_id, status,
 * submitted_at and the three decision columns are written by
 * AttendanceCorrectionWorkflow with forceFill() / forceCreate(), so a
 * hand-crafted payload can never file a request under somebody else's name
 * or arrive pre-approved.
 *
 * @property int $id
 * @property int $user_id
 * @property ?int $attendance_id
 * @property CarbonImmutable $attendance_date
 * @property CorrectionReason $reason
 * @property ?string $requested_check_in_time
 * @property ?string $requested_check_out_time
 * @property ?string $note
 * @property RequestStatus $status
 * @property CarbonImmutable $submitted_at
 * @property ?int $decided_by_id
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_note
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $user
 * @property-read ?Attendance $attendance
 * @property-read ?User $decidedBy
 */
#[Fillable([
    'attendance_id', 'attendance_date', 'reason',
    'requested_check_in_time', 'requested_check_out_time', 'note',
])]
final class AttendanceCorrection extends Model
{
    /** @use HasFactory<AttendanceCorrectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'reason' => CorrectionReason::class,
            'status' => RequestStatus::class,
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * The employee who asked, deleted accounts included.
     *
     * Without withTrashed() a request from a deleted account resolves to a
     * null user, and every screen that names the requester prints a blank
     * where the person was. The queue hides those requests through
     * actionable() instead, which is a decision about what to act on rather
     * than an accident of a global scope.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id')->withTrashed();
    }

    /**
     * The session being corrected, or null when the day held none.
     *
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending);
    }

    /**
     * Requests somebody can actually decide: pending, from an account that
     * still exists.
     *
     * Read from live state rather than stamped on a column when the account
     * is deleted. SoftDeletes fires its deleting event on a force delete
     * too, so a listener writing a flag would be rewriting history at the
     * worst possible moment; and reading the account's state means
     * restoring somebody brings their requests back into the queue for
     * free, which is exactly what restoring an account is meant to mean.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActionable(Builder $query): Builder
    {
        return $query
            ->pending()
            ->whereHas('user', fn (Builder $account): Builder => $account->whereNull('deleted_at'));
    }

    /**
     * The moment being asked for, composed from the day and the wall-clock
     * time already loaded on this row. No query, and no timezone guess: the
     * date cast is in the application timezone already.
     */
    public function requestedCheckInAt(): ?CarbonImmutable
    {
        return $this->composeMoment($this->requested_check_in_time);
    }

    public function requestedCheckOutAt(): ?CarbonImmutable
    {
        return $this->composeMoment($this->requested_check_out_time);
    }

    /**
     * "08:00 → 17:00", with an em dash standing in for the half of the day
     * the employee is not asking to change.
     */
    public function requestedRangeLabel(): string
    {
        return $this->formatTime($this->requested_check_in_time)
            .' → '
            .$this->formatTime($this->requested_check_out_time);
    }

    private function composeMoment(?string $time): ?CarbonImmutable
    {
        if ($time === null) {
            return null;
        }

        return $this->attendance_date->startOfDay()->setTimeFromTimeString($time);
    }

    /**
     * A stored TIME reads back as 'HH:MM:SS'; the interface prints minutes.
     */
    private function formatTime(?string $time): string
    {
        return $time === null ? '—' : substr($time, 0, 5);
    }
}
