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
 * One employee's attendance for one day.
 *
 * Written only by AttendanceWorkflow. Every timestamp is the server's; every
 * coordinate, accuracy and distance is what the server saw and computed at
 * the moment of the decision, kept so the record can be audited later.
 * Nothing edits a row after check-out - there is no interface for it and
 * the policies deny it - because an attendance record that can be edited is
 * not evidence of attendance.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $attendance_date
 * @property CarbonImmutable $check_in_at
 * @property string $check_in_latitude
 * @property string $check_in_longitude
 * @property string $check_in_accuracy
 * @property string $check_in_distance_from_company
 * @property ?CarbonImmutable $check_out_at
 * @property ?string $check_out_latitude
 * @property ?string $check_out_longitude
 * @property ?string $check_out_accuracy
 * @property ?string $check_out_distance_from_company
 * @property-read User $user
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
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->check_out_at === null;
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

    public function checkInCoordinates(): Coordinates
    {
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
}
