<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\PresencePing;
use App\Models\User;
use App\Support\Geo\LocationReading;

/**
 * Records where a checked-in employee's device reported itself to be.
 *
 * The rule is one sentence: a ping exists only while a session is open. No
 * open session today means nothing is written and the caller is told so -
 * a position recorded outside a session would be tracking an employee who
 * is not at work, which this system does not do.
 *
 * "Today" is the server's day, and the open session must be today's. A
 * session left open on an earlier day is a missing check-out, not a
 * presence; attaching this morning's position to it would invent hours
 * nobody worked, exactly as closing it today would.
 *
 * The distance and the verdict are computed here from the raw coordinates
 * by the same LocationVerifier the check-in path uses. Nothing the browser
 * says about how far away it is, or about what time it is, reaches this
 * table: the caller hands over a validated LocationReading - three numbers -
 * and the row's timestamp is the server's own.
 *
 * A poor fix is recorded rather than discarded. Unlike a check-in, a ping
 * decides nothing, and the accuracy is stored beside the position so
 * whoever reads the row can weigh it; dropping loose readings would quietly
 * thin the evidence and leave a gap that looks like an absence.
 */
final readonly class PresencePingRecorder
{
    public function __construct(
        private LocationVerifier $verifier,
        private AttendanceCalendar $calendar,
    ) {}

    /**
     * Records one ping, or returns null when there was no open session
     * today to attach it to.
     */
    public function record(User $user, LocationReading $reading): ?PresencePing
    {
        $session = $this->openSessionToday($user);

        if (! $session instanceof Attendance) {
            return null;
        }

        $verification = $this->verifier->verify($reading);
        $distance = $verification->roundedDistance();

        if ($distance === null) {
            // The company location has been cleared since the employee
            // checked in. There is no centre to measure from, and a
            // distance column filled with a guess would be worse than the
            // missing row.
            return null;
        }

        return PresencePing::query()->create([
            'user_id' => $user->id,
            'attendance_id' => $session->id,
            'latitude' => $reading->coordinates->latitude,
            'longitude' => $reading->coordinates->longitude,
            'accuracy' => round($reading->accuracyMeters, 2),
            'distance_from_company' => $distance,
            'is_inside' => $verification->isWithinRadius(),
        ]);
    }

    /**
     * The one session the employee is inside right now. The database allows
     * at most one open session per employee per day, so "the" is exact.
     */
    private function openSessionToday(User $user): ?Attendance
    {
        return Attendance::query()
            ->forUser($user)
            ->forDate($this->calendar->today())
            ->open()
            ->first();
    }
}
