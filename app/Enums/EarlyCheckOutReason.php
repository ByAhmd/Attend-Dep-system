<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an employee checked out before the end of the working day.
 *
 * Chosen by the employee at the moment of the check-out and stored on the
 * session it closed, so an early departure always carries its own account
 * of itself. Four cases and a free-text note rather than a longer list: a
 * reason an administrator can filter by has to be one of a handful of
 * words, and everything those words do not cover is Other plus the note.
 */
enum EarlyCheckOutReason: string
{
    case Sick = 'sick';
    case PersonalErrand = 'personal_errand';
    case WorkAssignment = 'work_assignment';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.early_check_out_reason.'.$this->value);
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
