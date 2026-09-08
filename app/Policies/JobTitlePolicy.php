<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JobTitle;
use App\Models\User;

/**
 * The list of job titles is an administrator's to keep.
 *
 * A title describes a person and grants nothing, so there is no rule here
 * about employment type, no rule about who holds which title, and nothing
 * for an employee to reach: the whole screen is administrator-only and the
 * verbs are the ordinary five.
 *
 * delete() asks only who is asking, never whether this particular title can
 * go. Whether it can is a question about the people wearing it, and it is
 * answered twice where the answer is cheap and current: by the foreign key,
 * which restricts the delete outright, and by the action's own modal, which
 * counts the holders it has already loaded. A policy that counted rows
 * would run a query per row of the list purely to decide whether to draw a
 * button.
 *
 * deleteAny() is false, as it is on every policy in this repository. There
 * is no bulk action anywhere in this product.
 */
final class JobTitlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, JobTitle $jobTitle): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, JobTitle $jobTitle): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, JobTitle $jobTitle): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
