<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Employee management is an administrator's job, and two of its powers
 * belong to the super administrator alone.
 *
 * Deleting an account and appointing or removing an administrator are the
 * two acts an ordinary administrator must not be able to perform: one
 * removes a colleague, the other hands out the keys. Both are reserved for
 * the account named in SUPER_ADMIN_EMAIL, which lives in the server's .env
 * and not in a row anybody could edit.
 *
 * Nothing here can touch the super administrator: they cannot be
 * deactivated, demoted, given a new password or deleted, by anybody,
 * themselves included. The way to retire that account is to change
 * SUPER_ADMIN_EMAIL on the server.
 *
 * Deleting is a soft delete - hide, keep records. The account stops signing
 * in and leaves the employee list; its attendance rows stay, still carrying
 * the person's name, and a super administrator can restore it.
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
     * Changing a status, or resetting a password - anything that alters
     * whether this account can get in. An administrator may do it to any
     * account but their own, so a deployment can never lock out its last
     * administrator by accident, and never to the super administrator,
     * whose way in is not the database's to take away.
     */
    public function manageAccess(User $user, User $target): bool
    {
        return $user->isAdmin()
            && ! $target->is($user)
            && ! $target->isSuperAdmin();
    }

    /**
     * Changing what an account is allowed to be - its role.
     *
     * Reserved for the super administrator: promoting somebody to
     * administrator hands them every screen in the panel, and demoting one
     * takes it away. An ordinary administrator keeps every other power over
     * an account and none of this one.
     *
     * The super administrator still cannot change their own role, which is
     * moot - the role column does not decide who they are - but keeps the
     * existing rule honest: nobody edits their own access on this screen.
     */
    public function manageRole(User $user, User $target): bool
    {
        return $user->isSuperAdmin() && $this->manageAccess($user, $target);
    }

    /**
     * Hide the account and keep its records. Super administrator only, and
     * never the super administrator themselves.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isSuperAdmin()
            && ! $target->is($user)
            && ! $target->isSuperAdmin();
    }

    /**
     * No bulk delete anywhere. Removing a colleague is done one at a time,
     * with their name in the confirmation.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $target): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Erasing the row for good.
     *
     * Allowed to the super administrator and offered nowhere in the
     * interface: "delete" in this system means hide and keep the records,
     * and the foreign keys refuse it anyway for anybody who ever checked in.
     * It exists so the gate has an answer, not so a screen can call it.
     */
    public function forceDelete(User $user, User $target): bool
    {
        return $user->isSuperAdmin()
            && ! $target->is($user)
            && ! $target->isSuperAdmin();
    }
}
