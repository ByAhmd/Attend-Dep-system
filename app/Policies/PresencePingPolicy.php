<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PresencePing;
use App\Models\User;

/**
 * Pings are read by administrators only, and written by nobody.
 *
 * Employees have no ping screen at all: their own pings tell them nothing
 * they do not already know, and another employee's whereabouts are none of
 * their business. Writes are denied for everyone, administrators included -
 * PresencePingRecorder is the only writer, and evidence an administrator
 * could add to or amend afterwards would not be evidence.
 */
final class PresencePingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, PresencePing $ping): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PresencePing $ping): bool
    {
        return false;
    }

    public function delete(User $user, PresencePing $ping): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
