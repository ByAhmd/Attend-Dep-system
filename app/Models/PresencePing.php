<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Geo\Coordinates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position a checked-in employee's browser reported while the
 * attendance page was open.
 *
 * A ping is supporting evidence and nothing more. The page can only report
 * while it is open and the phone is awake, so pings present are a fact and
 * pings absent are not: a gap means the page was closed, the screen was
 * off, the signal was gone, or the employee was away, and the row cannot
 * tell those apart. Everything stored here was measured by the server from
 * the three numbers the browser sent, so what a ping does say - this device
 * was 40 m from the company at 10:20 - it says on the server's authority.
 *
 * Written only by PresencePingRecorder, and never updated: hence created_at
 * without updated_at.
 *
 * @property int $id
 * @property int $user_id
 * @property int $attendance_id
 * @property string $latitude
 * @property string $longitude
 * @property string $accuracy
 * @property string $distance_from_company
 * @property bool $is_inside
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Attendance $attendance
 */
#[Fillable(['user_id', 'attendance_id', 'latitude', 'longitude', 'accuracy', 'distance_from_company', 'is_inside'])]
final class PresencePing extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'distance_from_company' => 'decimal:2',
            'is_inside' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The session this ping was taken during. A ping exists only while a
     * session is open, so this is never null.
     *
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function coordinates(): Coordinates
    {
        return new Coordinates((float) $this->latitude, (float) $this->longitude);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }
}
