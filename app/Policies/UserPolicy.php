<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Employee management is an administrator's job, and everything it can do
 * to another administrator belongs to the super administrator alone.
 *
 * Deleting an account, appointing or removing an administrator, and taking
 * a colleague's access away - their status, their password, the address
 * they sign in with - are the acts an ordinary administrator must not be
 * able to perform: the first removes a colleague, the second hands out the
 * keys, the third settles an argument between two administrators by force.
 * All three are reserved for the account named in SUPER_ADMIN_EMAIL, which
 * lives in the server's .env and not in a row anybody could edit.
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
     * Changing a status, resetting a password, rewriting the address the
     * account signs in with - anything that alters whether this account can
     * get in, or who "this account" is.
     *
     * An administrator may do it to an employee, never to themselves, and
     * never to another administrator: administrators do not switch each
     * other off. Without that last rule a disagreement between two of them
     * is settled by whoever opens the employee list first; a mis-click on
     * the wrong row locks a colleague out of the system they are
     * responsible for; and one compromised session is enough to shut every
     * other administrator out and be left alone inside. An administrator
     * keeps every power over an employee and none over a colleague.
     *
     * Refusing it on your own account is the older half of the same idea,
     * and it is what stops a deployment locking out its last administrator
     * by accident.
     *
     * Somebody must still be able to retire an administrator, and that is
     * the super administrator, whose reach here is over anybody but
     * themselves - and never over the super administrator, whose way in is
     * not the database's to take away.
     *
     * The address belongs in this question and not in update(). A name is a
     * label on a person; an address is the credential, and because the
     * super administrator is an address in .env rather than a column, an
     * administrator free to rewrite addresses could park the pinned one on
     * a spare row and claim it on their own - every rule above undone in
     * two saves, by the one field on the form nobody was watching.
     */
    public function manageAccess(User $user, User $target): bool
    {
        if (! $user->isAdmin() || $target->is($user) || $target->isSuperAdmin()) {
            return false;
        }

        return $user->isSuperAdmin() || ! $target->isAdmin();
    }

    /**
     * Changing what an account is allowed to be - its role.
     *
     * Reserved for the super administrator: promoting somebody to
     * administrator hands them every screen in the panel, and demoting one
     * takes it away. An ordinary administrator keeps every other power over
     * an employee's account and none of this one.
     *
     * Leaning on manageAccess() adds nothing here beyond its first two
     * rules - the actor is already the super administrator, for whom the
     * colleague rule never applies - and that is the point of asking it:
     * one place decides that nobody edits their own access on this screen,
     * and that the pinned account is beyond every act on it.
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
