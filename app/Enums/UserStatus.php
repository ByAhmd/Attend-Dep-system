<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account status.
 *
 * Accounts are switched off, never deleted: an employee who leaves keeps their
 * attendance history and the rows that reference them stay intact.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

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
