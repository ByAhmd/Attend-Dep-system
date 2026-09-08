<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's request for days off, and the decision on it.
 *
 * The range is inclusive at both ends: a single day has starts_on equal to
 * ends_on, and dayCount() therefore counts calendar days, not the
 * difference between two dates. is_exit_and_return marks the case that is
 * not a day off at all - a few hours away, back the same day.
 *
 * Approved leave records what was agreed and nothing more. It never
 * suppresses a check-in, never creates or closes an attendance session, and
 * never excuses a missing check-out; an employee on leave who comes in
 * anyway records a perfectly ordinary session.
 *
 * The four attachment columns hold a supporting file - written together or
 * not at all, and enforced that way by the table. They are outside
 * #[Fillable] because a path is chosen by the upload handler, never sent by
 * the browser: a payload that could name its own path could name any file
 * on the disk.
 *
 * @property int $id
 * @property int $user_id
 * @property LeaveType $type
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property bool $is_exit_and_return
 * @property string $reason
 * @property ?string $attachment_path
 * @property ?string $attachment_name
 * @property ?int $attachment_size
 * @property ?string $attachment_mime_type
 * @property RequestStatus $status
 * @property CarbonImmutable $submitted_at
 * @property ?int $decided_by_id
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_note
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $user
 * @property-read ?User $decidedBy
 */
#[Fillable(['type', 'starts_on', 'ends_on', 'is_exit_and_return', 'reason'])]
final class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * The longest span one request may cover.
     *
     * Not a legal entitlement - the system tracks no balance - but a guard
     * against a mistyped year turning one request into three hundred days
     * of approved leave nobody meant to grant.
     */
    public const int MAX_DAYS = 90;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_exit_and_return' => 'boolean',
            'attachment_size' => 'integer',
            'status' => RequestStatus::class,
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * The employee who asked, deleted accounts included - a request with a
     * blank name where the person was is not a kept record.
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
     * Requests somebody can actually decide. See the identical scope on
     * AttendanceCorrection for why this reads live state rather than a
     * column stamped when the account was deleted.
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
     * Approved leave covering a given day.
     *
     * Approved only: a pending request is a question, and answering "is
     * this person on leave today" with a question would put an agreement on
     * the screen that nobody has made.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApprovedOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query
            ->where('status', RequestStatus::Approved)
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day);
    }

    /**
     * Calendar days covered, both ends included, derived in PHP from two
     * dates already loaded - never a per-row DATEDIFF.
     */
    public function dayCount(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * Whether the leave covers the calendar day the given moment falls in.
     *
     * The comparison is on the day and never on the instant. starts_on and
     * ends_on are dates, so Carbon reads them at midnight; comparing a
     * check-in stamped 08:00 against ends_on at 00:00 would put the last
     * morning of a five-day leave outside its own range. Reducing both
     * sides to a date string is also what scopeApprovedOn() does, so the
     * question gets the same answer whether it is asked of one loaded row
     * or of the table.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->starts_on->toDateString()
            && $day <= $this->ends_on->toDateString();
    }
}
