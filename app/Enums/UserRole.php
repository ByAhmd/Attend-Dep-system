<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two access levels of the system.
 *
 * Admin manages employees, reads attendance and configures the company
 * location. Employee checks in and out and reads only their own history.
 * There is deliberately no finer permission model: the brief fixes exactly
 * these two levels, and a matrix would be machinery without a user.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Employee = 'employee';

    public function label(): string
    {
        return __('enums.user_role.'.$this->value);
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
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
