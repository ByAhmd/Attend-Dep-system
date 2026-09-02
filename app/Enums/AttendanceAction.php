<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two things an employee can do with their attendance.
 */
enum AttendanceAction: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';

    public function label(): string
    {
        return __('enums.attendance_action.'.$this->value);
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
