<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\User;

/**
 * Who may read a correction request, make one, and answer one.
 *
 * An employee reads their own and files new ones while their account is
 * active. An administrator reads every request and decides any of them but
 * their own.
 *
 * Nobody decides their own request, administrators included. An
 * administrator is staff and checks in like everybody else, so an
 * administrator who could approve their own correction would be typing
 * their own check-in time into the record that exists to say when they
 * arrived. This mirrors UserPolicy::manageAccess(), which already refuses
 * an administrator their own access controls.
 *
 * The disclosed consequence: on a deployment with exactly one
 * administrator, that person cannot correct their own attendance. It is a
 * decision and not an oversight, and it has the same remedy as the existing
 * rule that an administrator cannot deactivate themselves - appoint a
 * second administrator. The screen says so in words, so the owner meets it
 * as a sentence rather than as a button that is not there.
 *
 * A decided request is never re-decided, which is why decide() reads the
 * status as well as the person. Un-approving a correction would mean
 * un-writing an attendance amendment, and that either erases the archived
 * original moment or leaves an original that nothing explains. The remedy
 * for a decision somebody regrets is a second correction - which is also a
 * truthful account of what happened.
 *
 * update() and delete() are false for everybody. A request is a record of
 * something a person asked for on a particular day; editing it afterwards
 * would make the audit trail a draft.
 */
final class AttendanceCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AttendanceCorrection $request): bool
    {
        return $user->isAdmin() || (int) $request->user_id === (int) $user->id;
    }

    /**
     * Filing a request needs an active account and nothing else. Whether
     * there is any allowance left this month is a question for
     * CorrectionQuota, asked where the employee can be told the answer.
     */
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Answering a request: an administrator, a request still open, somebody
     * else's, and an account that still exists.
     */
    public function decide(User $user, AttendanceCorrection $request): bool
    {
        return $user->isAdmin()
            && $request->status->isPending()
            && (int) $request->user_id !== (int) $user->id
            && ! $request->user->trashed();
    }

    public function update(User $user, AttendanceCorrection $request): bool
    {
        return false;
    }

    public function delete(User $user, AttendanceCorrection $request): bool
    {
        return false;
    }

    /**
     * No bulk anything. Twelve requests are twelve decisions about twelve
     * people.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }
}
