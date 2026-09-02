<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one attendance record, derived rather than stored.
 *
 * An open record on today's date is a live session. An open record on an
 * earlier date is an employee who never checked out - kept open on purpose
 * so the administrator can see it, and never closed automatically, because
 * inventing a check-out time would be inventing attendance.
 */
enum AttendanceStatus: string
{
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case MissingCheckOut = 'missing_check_out';

    public function label(): string
    {
        return __('enums.attendance_status.'.$this->value);
    }

    /**
     * Filament badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::CheckedIn => 'success',
            self::CheckedOut => 'gray',
            self::MissingCheckOut => 'warning',
        };
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
