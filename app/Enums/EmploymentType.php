<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How somebody is engaged by the company: on the payroll, or training.
 *
 * A description of a person and never a permission. An intern signs in the
 * same way, sees the same screen, and checks in against the same radius as
 * anybody else; what an account may DO is answered by UserRole and by
 * nothing else. PanelAccess, UserPolicy and User::isAdmin() must therefore
 * never read this enum - the moment one of them does, a caption printed
 * under a name has quietly become an access rule, and the next person to
 * change somebody's title would be changing what they can reach.
 */
enum EmploymentType: string
{
    case Employee = 'employee';
    case Intern = 'intern';

    public function label(): string
    {
        return __('enums.employment_type.'.$this->value);
    }

    public function isIntern(): bool
    {
        return $this === self::Intern;
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
