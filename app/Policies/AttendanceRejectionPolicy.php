<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceRejection;
use App\Models\User;

/**
 * The audit trail is for administrators only, and read-only even for them.
 */
final class AttendanceRejectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AttendanceRejection $rejection): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AttendanceRejection $rejection): bool
    {
        return false;
    }

    public function delete(User $user, AttendanceRejection $rejection): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
