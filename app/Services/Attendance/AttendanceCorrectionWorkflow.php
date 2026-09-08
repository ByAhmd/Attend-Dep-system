<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Data\Attendance\CorrectionDraft;
use App\Enums\CorrectionRefusalReason;
use App\Enums\RequestStatus;
use App\Exceptions\Attendance\AttendanceCorrectionRefusedException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Correction requests: filing one, and carrying one out.
 *
 * This is the only thing in the system besides AttendanceWorkflow that
 * writes to `attendances`, and the only thing at all that amends a row
 * already there. Everything it does is arranged around one promise: what
 * the device recorded is never destroyed. The stored moment moves into
 * original_check_in_at / original_check_out_at before the corrected one
 * takes its place, the id of the approved request goes beside it, and the
 * coordinates, accuracy and distance are left exactly as the device
 * reported them - they describe the instant something was measured, and
 * hanging them on a time an administrator agreed to would turn a
 * measurement into a claim about a moment nothing measured.
 *
 * A request names a day and a wall-clock time. The two are composed into a
 * moment in Asia/Riyadh at approval and never before, because the row is
 * written when it is approved: a request filed at 08:00 proposing 17:00
 * today is in the future when it is written and in the past when it is
 * answered, and it is the answering that must not put attendance in the
 * future. Riyadh is +03 all year with no daylight saving, so a wall-clock
 * time there is never ambiguous and never non-existent. That is the
 * assumption which would break silently if this system ever ran elsewhere.
 *
 * Approval locks the employee's whole day, not one row. AttendanceWorkflow
 * deliberately does not lock for a check-in read - a locking read of a row
 * that does not exist takes a gap lock, and at eight o'clock the whole
 * office would deadlock on the same gaps. That reasoning does not apply
 * here: corrections are rare, administrator-driven, and the question being
 * asked is about the day as a whole, since a corrected time can collide
 * with any other session on it.
 *
 * On any refusal the transaction has rolled back, not one column has
 * changed, and the request stays pending. It is never auto-rejected: an
 * administrator's decision the system could not carry out is not a decision
 * about the employee's claim, and writing a rejection into a permanent
 * record because of a database conflict would put a false judgement of a
 * person into the audit.
 */
final readonly class AttendanceCorrectionWorkflow
{
    public function __construct(
        private AttendanceCalendar $calendar,
        private CorrectionQuota $quota,
    ) {}

    /**
     * File a request. Nothing in `attendances` is touched here; a request
     * is a question, and only an approval answers it.
     *
     * @throws AttendanceCorrectionRefusedException
     */
    public function submit(User $employee, CorrectionDraft $draft): AttendanceCorrection
    {
        if (! $employee->isActive()) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::AccountNotActive);
        }

        if ($this->quota->allowance() < 1) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::CorrectionsDisabled);
        }

        if ($this->quota->remainingFor($employee) < 1) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::QuotaExhausted);
        }

        $today = $this->calendar->today();

        if ($draft->date->greaterThan($today)) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::DayInTheFuture);
        }

        // This month or last month. Far enough back that a forgotten tap is
        // still fixable after a payroll cycle nobody in this system runs,
        // and near enough that the day is still something a person can
        // actually remember.
        if ($draft->date->lessThan($today->subMonthNoOverflow()->startOfMonth())) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::DateTooOld);
        }

        if ($draft->checkInTime === null && $draft->checkOutTime === null) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::TimeMissing);
        }

        // The table refuses a check-out that precedes its check-in with a
        // CHECK constraint. Asking the same question here means a payload
        // that skipped the form reads a sentence rather than a 500.
        if ($this->requestedCheckOutPrecedesCheckIn($draft)) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::CheckOutBeforeCheckIn);
        }

        // A correction naming no session manufactures one, and a
        // manufactured session with no check-out would be open on a day
        // that has ended: AttendanceWorkflow::checkOut() closes a session
        // open TODAY, so nothing could ever close it. The system would have
        // created a permanent missing check-out it has no way to resolve.
        if ($draft->attendanceId === null && ($draft->checkInTime === null || $draft->checkOutTime === null)) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::IncompleteNewSession);
        }

        if ($draft->attendanceId !== null && ! $this->ownsSessionOnDay($employee, $draft)) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::SessionNotYours);
        }

        try {
            // forceCreate, and user_id from the argument: status,
            // submitted_at and the three decision columns are outside
            // #[Fillable] precisely so a payload can never file a request
            // under somebody else's name or arrive pre-approved.
            return AttendanceCorrection::query()->forceCreate([
                'user_id' => $employee->id,
                'attendance_id' => $draft->attendanceId,
                'attendance_date' => $draft->date->toDateString(),
                'reason' => $draft->reason,
                'requested_check_in_time' => $draft->checkInTime,
                'requested_check_out_time' => $draft->checkOutTime,
                'note' => $draft->note,
                'status' => RequestStatus::Pending,
                'submitted_at' => $this->calendar->now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // attendance_corrections_one_pending_per_day. The same rule the
            // form asks about, decided by the database when two taps arrive
            // together.
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::RequestAlreadyPending);
        }
    }

    /**
     * Approve a request and amend the day it is about.
     *
     * @throws AttendanceCorrectionRefusedException
     */
    public function approve(AttendanceCorrection $correction, User $decidedBy, ?string $note = null): Attendance
    {
        try {
            return DB::transaction(fn (): Attendance => $this->apply($correction, $decidedBy, $note));
        } catch (QueryException $exception) {
            // Reaching this means a service rule and a CHECK constraint
            // disagree - a defect to find, not a condition to handle - so it
            // is logged with enough to find it. A raw 500 on the screen
            // whose whole purpose is trust is the worst failure available.
            Log::error('An approved attendance correction could not be applied.', [
                'correction_id' => $correction->getKey(),
                'sqlstate' => $exception->getCode(),
            ]);

            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::CouldNotBeApplied);
        }
    }

    /**
     * Reject a request. Nothing in `attendances` changes; the note is the
     * whole of what the employee receives, which is why the service asks
     * for it as well as the form.
     *
     * @throws AttendanceCorrectionRefusedException
     */
    public function reject(AttendanceCorrection $correction, User $decidedBy, string $note): AttendanceCorrection
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('A rejected correction request must carry the reason it was rejected.');
        }

        try {
            return DB::transaction(function () use ($correction, $decidedBy, $note): AttendanceCorrection {
                $request = $this->lockedRequest($correction);

                $request->forceFill([
                    'status' => RequestStatus::Rejected,
                    'decided_by_id' => $decidedBy->getKey(),
                    'decided_at' => $this->calendar->now(),
                    'decision_note' => trim($note),
                ])->save();

                return $request;
            });
        } catch (QueryException $exception) {
            Log::error('A correction rejection could not be recorded.', [
                'correction_id' => $correction->getKey(),
                'sqlstate' => $exception->getCode(),
            ]);

            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::CouldNotBeApplied);
        }
    }

    /**
     * The whole of an approval, inside one transaction.
     *
     * @throws AttendanceCorrectionRefusedException
     */
    private function apply(AttendanceCorrection $correction, User $decidedBy, ?string $note): Attendance
    {
        $request = $this->lockedRequest($correction);

        // An inactive account does not block: deactivation means "cannot
        // get in", not "was never here", and somebody deactivated last
        // Thursday may have a perfectly valid request about last Tuesday. A
        // deleted one does block, because attendance is never written for
        // an account that has been taken out of the system.
        $employee = User::query()
            ->withTrashed()
            ->whereKey($request->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($employee->trashed()) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::RequesterAccountDeleted);
        }

        $day = $request->attendance_date->startOfDay();
        $now = $this->calendar->now();
        $sessions = $this->lockedDay($employee, $day);
        $session = $this->targetSession($request, $sessions);

        $checkInAt = $request->requested_check_in_time === null
            ? $session?->check_in_at
            : $day->setTimeFromTimeString($request->requested_check_in_time);

        $checkOutAt = $request->requested_check_out_time === null
            ? $session?->check_out_at
            : $day->setTimeFromTimeString($request->requested_check_out_time);

        // Nothing to hang a session on. Only a request that never went
        // through submit() can be shaped like this, and it is refused here
        // rather than allowed to reach a NOT NULL column.
        if ($checkInAt === null) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::IncompleteNewSession);
        }

        $this->refuseFutureMoments($checkInAt, $checkOutAt, $now);

        if ($checkOutAt instanceof CarbonImmutable && $checkOutAt->lessThan($checkInAt)) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::CheckOutBeforeCheckIn);
        }

        if ($session instanceof Attendance
            && $this->sameMoment($checkInAt, $session->check_in_at)
            && $this->sameMoment($checkOutAt, $session->check_out_at)) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::NothingToChange);
        }

        $this->refuseDayCollisions($sessions, $session, $day, $checkInAt, $checkOutAt);

        // A manufactured session must be closed. An open one on a day that
        // has ended could never be closed by anything: checkOut() closes a
        // session open TODAY, so the system would have created a permanent
        // missing check-out with no way to resolve it. Asked after the
        // day's own rules, so a request that also collides with another
        // session reads the sentence about the collision, which is the more
        // specific thing that is wrong with it.
        if (! $session instanceof Attendance && $checkOutAt === null) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::IncompleteNewSession);
        }

        $amended = $session instanceof Attendance
            ? $this->amendSession($session, $request, $checkInAt, $checkOutAt)
            : $this->createSession($employee, $request, $day, $checkInAt, $checkOutAt);

        $request->forceFill([
            'status' => RequestStatus::Approved,
            'attendance_id' => $amended->getKey(),
            'decided_by_id' => $decidedBy->getKey(),
            'decided_at' => $now,
            'decision_note' => $this->optionalNote($note),
        ])->save();

        return $amended->refresh();
    }

    /**
     * The request as it stands right now, held until the transaction ends.
     *
     * Two administrators pressing Approve on the same request queue here;
     * the second one reads a decided request and is told so. A decided
     * request is never re-decided: un-approving would mean un-writing an
     * attendance amendment, which either erases the archived original or
     * leaves an original that nothing explains. The remedy for a wrong
     * decision is a second correction, which is also what actually
     * happened.
     *
     * @throws AttendanceCorrectionRefusedException
     */
    private function lockedRequest(AttendanceCorrection $correction): AttendanceCorrection
    {
        $request = AttendanceCorrection::query()
            ->whereKey($correction->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $request->status->isPending()) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::AlreadyDecided);
        }

        return $request;
    }

    /**
     * Every session the employee has on that day, locked together.
     *
     * @return Collection<int, Attendance>
     */
    private function lockedDay(User $employee, CarbonInterface $day): Collection
    {
        return Attendance::query()
            ->forUser($employee)
            ->forDate($day)
            ->inSessionOrder()
            ->lockForUpdate()
            ->get();
    }

    /**
     * The session the request names, resolved from the locked day so no
     * second query can see a different one.
     *
     * @param  Collection<int, Attendance>  $sessions
     *
     * @throws AttendanceCorrectionRefusedException
     */
    private function targetSession(AttendanceCorrection $request, Collection $sessions): ?Attendance
    {
        if ($request->attendance_id === null) {
            return null;
        }

        $session = $sessions->firstWhere('id', $request->attendance_id);

        if (! $session instanceof Attendance) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::SessionNotYours);
        }

        return $session;
    }

    /**
     * @throws AttendanceCorrectionRefusedException
     */
    private function refuseFutureMoments(CarbonImmutable $checkInAt, ?CarbonImmutable $checkOutAt, CarbonImmutable $now): void
    {
        foreach ([$checkInAt, $checkOutAt] as $moment) {
            if ($moment instanceof CarbonImmutable && $moment->greaterThan($now)) {
                throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::MomentInTheFuture);
            }
        }
    }

    /**
     * The two rules about the day as a whole, walked in PHP over the
     * sessions already locked and loaded - no second query, and no reliance
     * on an index to be the messenger.
     *
     * A second open session is refused by the unique index too, but that
     * index exists for a concurrent tap. An administrator's deliberate act
     * deserves a sentence saying what is wrong, not a constraint violation.
     *
     * Overlap has no database backstop on either engine, and it is the one
     * that quietly corrupts a total: a corrected 08:00-17:00 sitting on top
     * of an untouched 12:00-13:00 makes the day read as ten hours on the
     * employee's own screen. An open session is treated as running to the
     * end of its attendance day, because that is the widest thing it can
     * yet turn out to be.
     *
     * @param  Collection<int, Attendance>  $sessions
     *
     * @throws AttendanceCorrectionRefusedException
     */
    private function refuseDayCollisions(
        Collection $sessions,
        ?Attendance $session,
        CarbonImmutable $day,
        CarbonImmutable $checkInAt,
        ?CarbonImmutable $checkOutAt,
    ): void {
        $others = $sessions->reject(
            static fn (Attendance $other): bool => $session instanceof Attendance && $other->is($session),
        );

        $openAfterwards = $others->filter(static fn (Attendance $other): bool => $other->isOpen())->count()
            + ($checkOutAt === null ? 1 : 0);

        if ($openAfterwards > 1) {
            throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::TwoOpenSessions);
        }

        $dayEnd = $day->endOfDay();
        $newEnd = $checkOutAt ?? $dayEnd;

        foreach ($others as $other) {
            $otherEnd = $other->check_out_at ?? $dayEnd;

            if ($checkInAt->lessThan($otherEnd) && $newEnd->greaterThan($other->check_in_at)) {
                throw new AttendanceCorrectionRefusedException(CorrectionRefusalReason::OverlappingSession);
            }
        }
    }

    /**
     * A day that held no session at all, given the one the employee says
     * happened.
     *
     * Every geo column stays NULL, and the database allows that only
     * because both correction ids explain it: a recorded moment without
     * coordinates could then only have come from an approved correction,
     * which is a stronger guarantee than the one it replaced.
     */
    private function createSession(
        User $employee,
        AttendanceCorrection $request,
        CarbonImmutable $day,
        CarbonImmutable $checkInAt,
        CarbonImmutable $checkOutAt,
    ): Attendance {
        return Attendance::query()->forceCreate([
            'user_id' => $employee->getKey(),
            'attendance_date' => $day->toDateString(),
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'check_in_correction_id' => $request->getKey(),
            'check_out_correction_id' => $request->getKey(),
        ]);
    }

    /**
     * Amend one or both moments of a session that already exists.
     *
     * A half the request did not name, or asked for the time already
     * stored, is left completely alone: marking it corrected would print
     * "the device recorded 08:00" beside a check-in the device did record
     * at 08:00, which is a true sentence in a place that means something
     * went wrong.
     *
     * The archive is written from the value read under the lock and only
     * where nothing has been archived yet, so a second correction of the
     * same moment moves the effective time again and still leaves
     * original_* holding what the device said the first time. That is the
     * whole promise: the device's record survives every correction, not
     * only the first.
     */
    private function amendSession(
        Attendance $session,
        AttendanceCorrection $request,
        CarbonImmutable $checkInAt,
        ?CarbonImmutable $checkOutAt,
    ): Attendance {
        /** @var array<string, mixed> $changes */
        $changes = [];

        if ($request->requested_check_in_time !== null && ! $this->sameMoment($checkInAt, $session->check_in_at)) {
            if (! $session->isCheckInCorrected()) {
                $changes['original_check_in_at'] = $session->check_in_at;
            }

            $changes['check_in_at'] = $checkInAt;
            $changes['check_in_correction_id'] = $request->getKey();
        }

        if ($request->requested_check_out_time !== null && ! $this->sameMoment($checkOutAt, $session->check_out_at)) {
            if (! $session->isCheckOutCorrected()) {
                $changes['original_check_out_at'] = $session->check_out_at;
            }

            $changes['check_out_at'] = $checkOutAt;
            $changes['check_out_correction_id'] = $request->getKey();
        }

        $session->forceFill($changes)->save();

        return $session;
    }

    /**
     * Whether the session the draft names is this employee's, and belongs
     * to the day the draft is about.
     */
    private function ownsSessionOnDay(User $employee, CorrectionDraft $draft): bool
    {
        $session = Attendance::query()->whereKey($draft->attendanceId)->first();

        return $session instanceof Attendance
            && (int) $session->user_id === (int) $employee->id
            && $session->attendance_date->isSameDay($draft->date);
    }

    /**
     * Compared as composed moments rather than as strings, so a hand-built
     * draft writing '8:00' is judged the same way the form's '08:00' is.
     */
    private function requestedCheckOutPrecedesCheckIn(CorrectionDraft $draft): bool
    {
        if ($draft->checkInTime === null || $draft->checkOutTime === null) {
            return false;
        }

        return $draft->date->setTimeFromTimeString($draft->checkOutTime)
            ->lessThan($draft->date->setTimeFromTimeString($draft->checkInTime));
    }

    private function sameMoment(?CarbonInterface $left, ?CarbonInterface $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->equalTo($right);
    }

    /**
     * An approval may honestly carry no note; a blank one is absent rather
     * than empty, so the interface prints its placeholder.
     */
    private function optionalNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : $trimmed;
    }
}
