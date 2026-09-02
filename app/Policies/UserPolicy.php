<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Employee management is an administrator's job and nobody else's.
 *
 * Accounts are never deleted through the interface: an employee with
 * attendance history is deactivated so the history survives, and the
 * database restricts the delete anyway. Hence delete and its bulk form are
 * denied to everyone, administrators included.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Changing a role or status, or resetting a password - anything that
     * alters who may get in. An administrator may do it to any account but
     * their own, so a deployment can never lock out or demote its last
     * administrator by accident.
     */
    public function manageAccess(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->is($user);
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $target): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $target): bool
    {
        return false;
    }
}
