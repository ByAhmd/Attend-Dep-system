<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Turns an invited account into a working one the moment its owner sets a
 * password.
 *
 * Choosing the password is the whole of accepting an invitation: there is
 * no separate "accept" screen to hang this on, so the framework event that
 * fires once the new hash is saved is where the status changes.
 *
 * Only Pending is promoted. An Active account resetting a forgotten
 * password is already where it should be, and an Inactive one must stay
 * inactive - a deactivated employee who still holds an old link must not be
 * able to let themselves back in by completing a reset.
 *
 * Registered by Laravel's event discovery, which scans app/Listeners for a
 * handle() method typed against an event; no manual binding exists or is
 * needed.
 */
final class ActivateInvitedEmployee
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->status->isPending()) {
            return;
        }

        $user->forceFill(['status' => UserStatus::Active])->save();
    }
}
