<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Data\Leave\LeaveDraft;
use App\Enums\LeaveRefusalReason;
use App\Enums\RequestStatus;
use App\Events\RequestDecided;
use App\Events\RequestSubmitted;
use App\Exceptions\Leave\LeaveRequestRefusedException;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Leave requests: filing one, and deciding one.
 *
 * A leave request records what was agreed and does nothing else. It never
 * suppresses a check-in, never creates, closes or reopens an attendance
 * session, and never excuses a missing check-out. An employee on approved
 * leave who comes in anyway records a perfectly ordinary session: leave is
 * a plan made in advance, and the door is the door. Nothing in this file
 * writes to `attendances`, which is the point.
 *
 * Overlap is refused by this service under a lock, with its own sentence.
 * MariaDB 10.4 has no exclusion constraints, so there is no database
 * backstop for "these days are already spoken for"; the unique index on
 * (user_id, pending_starts_on) catches a double tap on the same start date
 * and nothing wider. The check runs again at approval, because a queue
 * worked in the wrong order can otherwise approve a second request into
 * days the first one already holds.
 *
 * Past start dates are allowed on purpose. A sick day is reported after the
 * fact, and that is the normal case, not the exception.
 *
 * Filing one and deciding one each announce themselves with an event once
 * the transaction has returned, exactly as AttendanceCorrectionWorkflow
 * does and for the same reason: a request that was refused, or a decision
 * that rolled back, must be announced to nobody. Who is told lives in
 * app/Listeners and is no concern of this service.
 */
final readonly class LeaveRequestWorkflow
{
    /**
     * How far back a request may start. Long enough that last month's sick
     * day can still be recorded, short enough that a leave request is
     * always about something somebody still remembers.
     */
    private const int EARLIEST_START_DAYS = 30;

    public function __construct(private AttendanceCalendar $calendar) {}

    /**
     * @throws LeaveRequestRefusedException
     */
    public function submit(User $employee, LeaveDraft $draft): LeaveRequest
    {
        if (! $employee->isActive()) {
            throw new LeaveRequestRefusedException(LeaveRefusalReason::AccountNotActive);
        }

        if ($draft->endsOn->lessThan($draft->startsOn)) {
            throw new LeaveRequestRefusedException(LeaveRefusalReason::EndBeforeStart);
        }

        $today = $this->calendar->today();

        if ($draft->startsOn->lessThan($today->subDays(self::EARLIEST_START_DAYS))
            || $draft->startsOn->greaterThan($today->addYear())) {
            throw new LeaveRequestRefusedException(LeaveRefusalReason::DateOutOfRange);
        }

        if ($draft->dayCount() > LeaveRequest::MAX_DAYS) {
            throw new LeaveRequestRefusedException(LeaveRefusalReason::TooLong);
        }

        try {
            $request = DB::transaction(function () use ($employee, $draft): LeaveRequest {
                if ($this->clashes($employee, $draft->startsOn, $draft->endsOn, null, includePending: true)) {
                    throw new LeaveRequestRefusedException(LeaveRefusalReason::Overlapping);
                }

                // forceCreate, with user_id from the argument: status,
                // submitted_at, the decision columns and the four
                // attachment columns are all outside #[Fillable], so a
                // payload can neither file under another name nor arrive
                // approved nor name its own file on the disk.
                return LeaveRequest::query()->forceCreate([
                    'user_id' => $employee->id,
                    'type' => $draft->type,
                    'starts_on' => $draft->startsOn->toDateString(),
                    'ends_on' => $draft->endsOn->toDateString(),
                    'is_exit_and_return' => $draft->isExitAndReturn,
                    'reason' => $draft->reason,
                    'status' => RequestStatus::Pending,
                    'submitted_at' => $this->calendar->now(),
                    'attachment_path' => $draft->attachmentPath,
                    'attachment_name' => $draft->attachmentName,
                    'attachment_size' => $draft->attachmentSize,
                    'attachment_mime_type' => $draft->attachmentMimeType,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // leave_requests_one_pending_start_per_day. A double tap and a
            // deliberate clash read the same sentence, which is the right
            // sentence for both: the days are already asked for.
            throw new LeaveRequestRefusedException(LeaveRefusalReason::Overlapping);
        }

        // Outside the transaction, so a refused request announces nothing and
        // nothing a listener does can reach the row that was written.
        RequestSubmitted::dispatch($request);

        return $request;
    }

    /**
     * @throws LeaveRequestRefusedException
     */
    public function approve(LeaveRequest $request, User $decidedBy, ?string $note = null): LeaveRequest
    {
        return $this->decide($request, $decidedBy, RequestStatus::Approved, $note);
    }

    /**
     * The note is the whole of what the employee receives in place of the
     * approval they asked for, so the service asks for it as well as the
     * form.
     *
     * @throws LeaveRequestRefusedException
     */
    public function reject(LeaveRequest $request, User $decidedBy, string $note): LeaveRequest
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('A rejected leave request must carry the reason it was rejected.');
        }

        return $this->decide($request, $decidedBy, RequestStatus::Rejected, $note);
    }

    /**
     * @throws LeaveRequestRefusedException
     */
    private function decide(LeaveRequest $request, User $decidedBy, RequestStatus $status, ?string $note): LeaveRequest
    {
        try {
            $decided = DB::transaction(function () use ($request, $decidedBy, $status, $note): LeaveRequest {
                $locked = LeaveRequest::query()
                    ->whereKey($request->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->status->isPending()) {
                    throw new LeaveRequestRefusedException(LeaveRefusalReason::AlreadyDecided);
                }

                $employee = User::query()
                    ->withTrashed()
                    ->whereKey($locked->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($employee->trashed()) {
                    throw new LeaveRequestRefusedException(LeaveRefusalReason::RequesterAccountDeleted);
                }

                // Only an approval can create a clash. A rejection agrees
                // to nothing, so days already held are none of its
                // business.
                if ($status === RequestStatus::Approved
                    && $this->clashes($employee, $locked->starts_on, $locked->ends_on, $locked->getKey(), includePending: false)) {
                    throw new LeaveRequestRefusedException(LeaveRefusalReason::Overlapping);
                }

                $locked->forceFill([
                    'status' => $status,
                    'decided_by_id' => $decidedBy->getKey(),
                    'decided_at' => $this->calendar->now(),
                    'decision_note' => $this->optionalNote($note),
                ])->save();

                return $locked;
            });
        } catch (QueryException $exception) {
            Log::error('A leave decision could not be recorded.', [
                'leave_request_id' => $request->getKey(),
                'sqlstate' => $exception->getCode(),
            ]);

            throw new LeaveRequestRefusedException(LeaveRefusalReason::CouldNotBeApplied);
        }

        RequestDecided::dispatch($decided);

        return $decided;
    }

    /**
     * Whether this employee already holds any of those days.
     *
     * Inclusive at both ends and locked, so two requests filed together
     * cannot both pass. Adjacency is fine: ending on the 5th and starting
     * on the 6th is two requests, not one clash. Overlap between different
     * employees is not a concept here - this system records no staffing
     * level to protect.
     *
     * At submission the question includes pending requests, because two
     * open questions about the same days are one question asked twice. At
     * approval it does not: a pending request is not yet a fact, and the
     * one being approved would otherwise be blocked by a request that has
     * agreed to nothing.
     */
    private function clashes(
        User $employee,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?int $ignoreKey,
        bool $includePending,
    ): bool {
        $statuses = $includePending
            ? [RequestStatus::Pending, RequestStatus::Approved]
            : [RequestStatus::Approved];

        return LeaveRequest::query()
            ->forUser($employee)
            ->whereIn('status', array_map(static fn (RequestStatus $status): string => $status->value, $statuses))
            ->when($ignoreKey !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreKey))
            ->where('starts_on', '<=', $endsOn->toDateString())
            ->where('ends_on', '>=', $startsOn->toDateString())
            ->lockForUpdate()
            ->exists();
    }

    private function optionalNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : $trimmed;
    }
}
