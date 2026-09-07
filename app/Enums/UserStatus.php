<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account status.
 *
 * Accounts are switched off, never deleted: an employee who leaves keeps their
 * attendance history and the rows that reference them stay intact.
 *
 * A newly created account starts Pending: it exists, it has no password, and
 * it becomes Active the moment its owner follows the invitation and chooses
 * one. Until then it is as locked out as a deactivated account.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';

    public function label(): string
    {
        return __('enums.user_status.'.$this->value);
    }

    /**
     * Only an active account may sign in or record attendance. This is the
     * single place that answers the question, so a status added later cannot
     * quietly slip through a panel guard that forgot to check it.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the account is still waiting for its owner to set a password.
     *
     * Asked wherever the interface has to choose between the invitation flow
     * and the ordinary account controls, so "invited" is spelled one way.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
