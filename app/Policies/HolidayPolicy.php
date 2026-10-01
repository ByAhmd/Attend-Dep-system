<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

/**
 * The holiday calendar is an administrator's to keep.
 *
 * A holiday constrains nobody and grants nothing - it only keeps the
 * screens that report somebody missing from reporting a day nobody was
 * expected - so the whole screen is administrator-only and the verbs are
 * the ordinary five. Unlike an attendance row it records a decision, not
 * evidence, so one entered wrong is deleted outright.
 *
 * deleteAny() is false, as it is on every policy in this repository.
 * There is no bulk action anywhere in this product.
 */
final class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
