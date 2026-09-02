<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceSetting;
use App\Models\User;

/**
 * The company location and radius are changed by administrators only. The
 * row is never created or deleted from the interface - the model creates it
 * on first use and there is always exactly one.
 */
final class AttendanceSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AttendanceSetting $setting): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AttendanceSetting $setting): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, AttendanceSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
