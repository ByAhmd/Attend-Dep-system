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
 * The POSITION of a refused attempt is deliberately not here. A refused
 * check-in is a moment the person stood somewhere this product has no
 * business recording - most often their home - so the row keeps the whole
 * diagnosis and none of the address: the reason, the accuracy, and the
 * distance from the company. The distance is a measurement about the
 * line, not a place; a map pin cannot be rebuilt from it.
 *
 * @property int $id
 * @property int $user_id
 * @property AttendanceAction $action
 * @property string $accuracy
 * @property ?string $distance_from_company
 * @property AttendanceRejectionReason $reason
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'action', 'accuracy', 'distance_from_company', 'reason'])]
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
            'accuracy' => 'decimal:2',
            'distance_from_company' => 'decimal:2',
            'reason' => AttendanceRejectionReason::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * The employee whose attempt was refused, deleted accounts included.
     *
     * This is an audit trail: an entry that stops naming anybody after the
     * account is deleted has stopped being one. withTrashed() keeps the name
     * on the row.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
