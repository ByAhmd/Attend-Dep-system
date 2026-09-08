<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Who may read a leave request, make one, and answer one.
 *
 * Verb for verb the same as AttendanceCorrectionPolicy, and deliberately
 * so: the two request kinds differ in what they are about and in nothing
 * about who may act on them, and one shape means an administrator learns
 * the rule once.
 *
 * Nobody decides their own request, administrators included: an
 * administrator granting themselves leave is the same act as an
 * administrator granting themselves a check-in time. On a deployment with
 * one administrator that person's own leave cannot be approved from inside
 * the system; the remedy is a second administrator.
 *
 * A decided request is never re-decided and no request is ever edited or
 * deleted. What somebody asked for, and what they were told, is the whole
 * value of the record.
 */
final class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->isAdmin() || (int) $request->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function decide(User $user, LeaveRequest $request): bool
    {
        return $user->isAdmin()
            && $request->status->isPending()
            && (int) $request->user_id !== (int) $user->id
            && ! $request->user->trashed();
    }

    public function update(User $user, LeaveRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, LeaveRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
