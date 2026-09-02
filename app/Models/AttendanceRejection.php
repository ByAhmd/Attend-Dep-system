<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A check-in or check-out the backend refused because of where the device
 * was, or how badly it knew where it was.
 *
 * The practical audit trail of the system: it answers "why could I not
 * check in?" for support and "who keeps trying from across town?" for
 * security. Append-only - rows are never updated, so there is no updated_at.
 *
 * @property int $id
 * @property int $user_id
 * @property AttendanceAction $action
 * @property string $latitude
 * @property string $longitude
 * @property string $accuracy
 * @property ?string $distance_from_company
 * @property AttendanceRejectionReason $reason
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'action', 'latitude', 'longitude', 'accuracy', 'distance_from_company', 'reason'])]
final class AttendanceRejection extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AttendanceAction::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'distance_from_company' => 'decimal:2',
            'reason' => AttendanceRejectionReason::class,
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
}
