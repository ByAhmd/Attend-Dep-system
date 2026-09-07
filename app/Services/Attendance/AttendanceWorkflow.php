<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Data\Attendance\LocationVerification;
use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\User;
use App\Support\Geo\LocationReading;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The attendance rules, in one place.
 *
 * A row is one session: a check-in and the check-out that closes it. An
 * employee who leaves during the day checks out and checks in again on
 * return, and the return is verified by the geofence like any other
 * check-in, so time inside and outside becomes visible.
 *
 * Check-in requires an active account, no OPEN session today, and a
 * trustworthy reading inside the radius. Sessions already completed today
 * are not in the way - that is the whole point - and a session left open on
 * an earlier day is not either: it stays open, is reported as a missing
 * check-out, and never becomes a reason to refuse someone standing at the
 * door this morning. Check-out requires an open session TODAY and the same
 * location test; yesterday's open session still cannot be closed today,
 * because a check-out time invented for a day that has ended is invented
 * attendance.
 *
 * Two taps arriving together cannot both succeed: a double check-in is
 * settled by the unique index on (user_id, open_attendance_date), a double
 * check-out by a row lock.
 *
 * Every moment recorded here is the server's. The browser sends
 * coordinates and accuracy, never a time and never a distance.
 */
final readonly class AttendanceWorkflow
{
    public function __construct(
        private LocationVerifier $verifier,
        private AttendanceCalendar $calendar,
    ) {}

    /**
     * @throws AttendanceRejectedException
     */
    public function checkIn(User $user, LocationReading $reading): Attendance
    {
        return $this->attempt(AttendanceAction::CheckIn, $user, $reading, function () use ($user, $reading): Attendance {
            // One clock read per operation: the day and the moment must
            // come from the same instant, or a request straddling midnight
            // could file a 00:00:00 check-in under the previous day.
            $now = $this->calendar->now();
            $today = $now->startOfDay();

            if ($this->openSessionOn($user, $today) instanceof Attendance) {
                throw new AttendanceRejectedException(AttendanceRejectionReason::AlreadyCheckedIn);
            }

            $verification = $this->verifiedLocation($reading);

            return Attendance::query()->create([
                'user_id' => $user->id,
                'attendance_date' => $today->toDateString(),
                'check_in_at' => $now,
                'check_in_latitude' => $reading->coordinates->latitude,
                'check_in_longitude' => $reading->coordinates->longitude,
                'check_in_accuracy' => round($reading->accuracyMeters, 2),
                'check_in_distance_from_company' => $verification->roundedDistance(),
            ]);
        });
    }

    /**
     * @throws AttendanceRejectedException
     */
    public function checkOut(User $user, LocationReading $reading): Attendance
    {
        return $this->attempt(AttendanceAction::CheckOut, $user, $reading, function () use ($user, $reading): Attendance {
            $now = $this->calendar->now();
            $today = $now->startOfDay();
            $session = $this->lockedOpenSessionOn($user, $today);

            if (! $session instanceof Attendance) {
                // Nothing open today. Which sentence the employee reads
                // depends on whether they have been here at all today: a
                // completed session means they simply already left, no
                // session at all means there is nothing to close.
                throw new AttendanceRejectedException(
                    $this->hasSessionOn($user, $today)
                        ? AttendanceRejectionReason::AlreadyCheckedOut
                        : AttendanceRejectionReason::NotCheckedIn,
                );
            }

            $verification = $this->verifiedLocation($reading);

            $session->forceFill([
                'check_out_at' => $now,
                'check_out_latitude' => $reading->coordinates->latitude,
                'check_out_longitude' => $reading->coordinates->longitude,
                'check_out_accuracy' => round($reading->accuracyMeters, 2),
                'check_out_distance_from_company' => $verification->roundedDistance(),
            ])->save();

            return $session;
        });
    }

    /**
     * Runs one operation under the shared guards, and records the rejection
     * when it fails. The audit row is written after the transaction has
     * rolled back - inside it, the rollback would erase it.
     *
     * @param  Closure(): Attendance  $operation
     *
     * @throws AttendanceRejectedException
     */
    private function attempt(AttendanceAction $action, User $user, LocationReading $reading, Closure $operation): Attendance
    {
        try {
            if (! $user->status->canAuthenticate()) {
                throw new AttendanceRejectedException(AttendanceRejectionReason::InactiveAccount);
            }

            return DB::transaction($operation);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request opened today's session between our read
            // and our insert, and attendances_one_open_session_per_day
            // refused the second one. It is the same rule, decided by the
            // database instead of the code.
            $rejection = new AttendanceRejectedException(AttendanceRejectionReason::AlreadyCheckedIn);
        } catch (AttendanceRejectedException $rejection) {
        }

        $this->recordRejection($user, $action, $reading, $rejection);

        throw $rejection;
    }

    /**
     * @throws AttendanceRejectedException
     */
    private function verifiedLocation(LocationReading $reading): LocationVerification
    {
        $verification = $this->verifier->verify($reading);
        $reason = $verification->rejectionReason();

        if ($reason instanceof AttendanceRejectionReason) {
            throw new AttendanceRejectedException($reason, $verification);
        }

        return $verification;
    }

    /**
     * Today's open session for a check-in, read WITHOUT a lock.
     *
     * A locking read for a row that does not exist takes a gap lock on the
     * unique index, and at eight o'clock every employee's check-in would
     * lock the same gaps and deadlock each other's inserts. The unique
     * index on (user_id, open_attendance_date) settles a genuine double
     * check-in on its own; attempt() turns that into the ordinary
     * "already checked in" answer.
     */
    private function openSessionOn(User $user, CarbonInterface $date): ?Attendance
    {
        return Attendance::query()
            ->forUser($user)
            ->forDate($date)
            ->open()
            ->first();
    }

    /**
     * Today's open session for a check-out, locked. The row exists, so this
     * is a record lock only: two simultaneous check-outs queue on it and the
     * second one finds nothing open left to close.
     */
    private function lockedOpenSessionOn(User $user, CarbonInterface $date): ?Attendance
    {
        return Attendance::query()
            ->forUser($user)
            ->forDate($date)
            ->open()
            ->lockForUpdate()
            ->first();
    }

    private function hasSessionOn(User $user, CarbonInterface $date): bool
    {
        return Attendance::query()
            ->forUser($user)
            ->forDate($date)
            ->exists();
    }

    private function recordRejection(
        User $user,
        AttendanceAction $action,
        LocationReading $reading,
        AttendanceRejectedException $rejection,
    ): void {
        if (! $rejection->reason->isRecorded()) {
            return;
        }

        AttendanceRejection::query()->create([
            'user_id' => $user->id,
            'action' => $action,
            'latitude' => $reading->coordinates->latitude,
            'longitude' => $reading->coordinates->longitude,
            'accuracy' => round($reading->accuracyMeters, 2),
            'distance_from_company' => $rejection->verification?->roundedDistance(),
            'reason' => $rejection->reason,
        ]);
    }
}
