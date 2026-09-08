<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Attendance records are read by administrators and by their own employee,
 * and written through the Gate by nobody at all.
 *
 * Two services write this table and neither asks a policy. AttendanceWorkflow
 * records a session from a verified location reading, and
 * AttendanceCorrectionWorkflow amends one - only ever acting on a request an
 * administrator approved, only ever after archiving the moment the device
 * recorded, and never rewriting a coordinate. Nothing else writes here.
 *
 * So create, update and delete stay hard false for everybody, administrators
 * included, and the answer does not soften now that corrections exist. There
 * is no attendance form to reach and AttendanceResource registers one page.
 * A record that an interface can edit is not a record of attendance; an
 * amendment that carries the request, the approver and the original moment
 * beside it still is.
 */
final class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $user->isAdmin() || (int) $attendance->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
