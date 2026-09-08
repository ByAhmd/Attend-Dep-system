<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceCalendar;
use App\Support\Geo\Coordinates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance session: a check-in and the check-out that closes it.
 *
 * An employee who leaves during the day checks out and checks in again on
 * return, so one day holds as many rows as the employee made sessions.
 * attendance_date stays the day the session started, and the database
 * allows at most one open session per employee per day (see the
 * open_attendance_date generated column, which nothing here writes or
 * reads: it exists only to carry that unique index).
 *
 * Written by AttendanceWorkflow and amended by exactly one other thing:
 * AttendanceCorrectionWorkflow, acting on a request an administrator
 * approved. Every timestamp is the server's; every coordinate, accuracy and
 * distance is what the server saw and computed at the moment of the
 * decision, kept so the record can be audited later. No interface edits a
 * row - there is none, and the policies deny every write - because an
 * attendance record that can be edited is not evidence of attendance.
 *
 * A correction never erases what the device said. check_in_at and
 * check_out_at go on holding the effective moment, so every screen and
 * every total reads one column; the moment the device recorded moves into
 * original_check_in_at / original_check_out_at first, and the correction id
 * beside it names the request that did it. The coordinates, accuracy and
 * distance are never rewritten - they describe the moment the device saw,
 * and attaching them to a corrected time would turn a measurement into a
 * claim about an instant nothing measured.
 *
 * The four check-in geo columns are therefore nullable now: a session
 * created wholly by a correction has no reading at all, and the database
 * allows that only where a correction id explains it.
 *
 * The two archive columns and the two correction ids are outside
 * #[Fillable], exactly as open_attendance_date is. Only the correction
 * workflow reaches them, and it does so through forceFill() /
 * forceCreate(), so no payload anywhere can archive a moment, un-archive
 * one, or claim that a time was corrected when it was not.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $attendance_date
 * @property CarbonImmutable $check_in_at
 * @property ?string $check_in_latitude
 * @property ?string $check_in_longitude
 * @property ?string $check_in_accuracy
 * @property ?string $check_in_distance_from_company
 * @property ?CarbonImmutable $check_out_at
 * @property ?string $check_out_latitude
 * @property ?string $check_out_longitude
 * @property ?string $check_out_accuracy
 * @property ?string $check_out_distance_from_company
 * @property ?CarbonImmutable $original_check_in_at
 * @property ?CarbonImmutable $original_check_out_at
 * @property ?int $check_in_correction_id
 * @property ?int $check_out_correction_id
 * @property-read User $user
 * @property-read ?AttendanceCorrection $checkInCorrection
 * @property-read ?AttendanceCorrection $checkOutCorrection
 */
#[Fillable([
    'user_id', 'attendance_date',
    'check_in_at', 'check_in_latitude', 'check_in_longitude', 'check_in_accuracy', 'check_in_distance_from_company',
    'check_out_at', 'check_out_latitude', 'check_out_longitude', 'check_out_accuracy', 'check_out_distance_from_company',
])]
final class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'check_in_at' => 'immutable_datetime',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_in_accuracy' => 'decimal:2',
            'check_in_distance_from_company' => 'decimal:2',
            'check_out_at' => 'immutable_datetime',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
            'check_out_accuracy' => 'decimal:2',
            'check_out_distance_from_company' => 'decimal:2',
            'original_check_in_at' => 'immutable_datetime',
            'original_check_out_at' => 'immutable_datetime',
        ];
    }

    /**
     * The employee this day belongs to, deleted accounts included.
     *
     * Deleting an employee hides the account but keeps their records, and a
     * record with a blank name is not a kept record. Without withTrashed()
     * the soft-delete scope makes this relation resolve to null and every
     * screen listing the day prints an empty name where the person was.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isOpen(): bool
    {
        return $this->check_out_at === null;
    }

    /**
     * How long the session lasted, or null while it is still running.
     *
     * Seconds rather than an interval: it is what the day's total is summed
     * in and what the display formatter takes, and both moments are stored
     * to the whole second, so nothing is lost. An open session has no
     * length yet - counting the minutes since its check-in would produce a
     * figure that changes every time a page is opened, and would read as
     * time already worked.
     */
    public function durationInSeconds(): ?int
    {
        if ($this->check_out_at === null) {
            return null;
        }

        return (int) $this->check_in_at->diffInSeconds($this->check_out_at);
    }

    /**
     * Derived, never stored: an open record is a live session on its own day
     * and a missing check-out on any earlier one.
     */
    public function status(): AttendanceStatus
    {
        if (! $this->isOpen()) {
            return AttendanceStatus::CheckedOut;
        }

        return $this->attendance_date->isSameDay(app(AttendanceCalendar::class)->today())
            ? AttendanceStatus::CheckedIn
            : AttendanceStatus::MissingCheckOut;
    }

    /**
     * The request that amended the check-in, if one did.
     *
     * @return BelongsTo<AttendanceCorrection, $this>
     */
    public function checkInCorrection(): BelongsTo
    {
        return $this->belongsTo(AttendanceCorrection::class, 'check_in_correction_id');
    }

    /**
     * @return BelongsTo<AttendanceCorrection, $this>
     */
    public function checkOutCorrection(): BelongsTo
    {
        return $this->belongsTo(AttendanceCorrection::class, 'check_out_correction_id');
    }

    public function isCheckInCorrected(): bool
    {
        return $this->check_in_correction_id !== null;
    }

    public function isCheckOutCorrected(): bool
    {
        return $this->check_out_correction_id !== null;
    }

    /**
     * Corrected-ness is a second axis beside the session's status: a
     * session can be checked out AND corrected, and a missing check-out is
     * the row most likely to have been corrected of all.
     */
    public function isCorrected(): bool
    {
        return $this->isCheckInCorrected() || $this->isCheckOutCorrected();
    }

    public function hasDeviceCheckIn(): bool
    {
        return $this->check_in_latitude !== null;
    }

    public function hasDeviceCheckOut(): bool
    {
        return $this->check_out_latitude !== null;
    }

    /**
     * What the device recorded for the check-in, or null where it recorded
     * nothing at all.
     *
     * An uncorrected moment IS the device's moment, so there is nothing
     * archived to read; a corrected one has its original beside it, and
     * that original is NULL precisely when the correction invented the
     * moment rather than moving one.
     */
    public function deviceCheckInAt(): ?CarbonImmutable
    {
        return $this->isCheckInCorrected() ? $this->original_check_in_at : $this->check_in_at;
    }

    public function deviceCheckOutAt(): ?CarbonImmutable
    {
        return $this->isCheckOutCorrected() ? $this->original_check_out_at : $this->check_out_at;
    }

    /**
     * Where the device was at check-in, or null when it never said.
     *
     * Nullable since a correction may supply a moment with no reading
     * behind it. Every caller has to decide what to do with the absence -
     * a map link to nowhere is worse than no map link.
     */
    public function checkInCoordinates(): ?Coordinates
    {
        if ($this->check_in_latitude === null || $this->check_in_longitude === null) {
            return null;
        }

        return new Coordinates((float) $this->check_in_latitude, (float) $this->check_in_longitude);
    }

    public function checkOutCoordinates(): ?Coordinates
    {
        if ($this->check_out_latitude === null || $this->check_out_longitude === null) {
            return null;
        }

        return new Coordinates((float) $this->check_out_latitude, (float) $this->check_out_longitude);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDate(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where('attendance_date', $date->toDateString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('check_out_at');
    }

    /**
     * One employee's sessions. Takes the model or the key, so a caller
     * holding either does not have to load the other.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * A day's sessions in the order they happened.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInSessionOrder(Builder $query): Builder
    {
        return $query->orderBy('attendance_date')->orderBy('check_in_at')->orderBy('id');
    }
}
