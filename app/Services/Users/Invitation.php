<?php

declare(strict_types=1);

namespace App\Services\Users;

/**
 * The outcome of issuing one invitation.
 *
 * The link is the part that always works; the email is the part that may
 * not. Keeping both in one object is what lets the administrator's screen
 * say "we emailed it" or "we could not - here is the link" without asking
 * the mail configuration anything itself.
 */
final readonly class Invitation
{
    public function __construct(
        /**
         * The absolute, single-use URL the employee opens to choose their
         * password. Safe to show an administrator: it is the only copy that
         * exists, since the token is stored hashed.
         */
        public string $url,
        /**
         * Whether the invitation email actually left the application. False
         * when no delivering mailer is configured and when sending threw.
         */
        public bool $emailed,
    ) {}
}
