<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Attendance records are read by administrators and by their own employee,
 * and written by nobody: AttendanceWorkflow is the only writer, and it does
 * not go through the Gate. There is no create, update or delete for anyone,
 * because a record that can be edited afterwards is not a record of
 * attendance.
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
