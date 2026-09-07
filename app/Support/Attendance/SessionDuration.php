<?php

declare(strict_types=1);

namespace App\Support\Attendance;

/**
 * A length of attendance as people read it.
 *
 * Durations are computed in seconds - that is what a sum of sessions needs
 * to stay exact - but nobody reads "16,320 s". Hours and minutes are what
 * an employee glancing at their day and an administrator reading a list
 * both act on, so seconds never reach a screen.
 */
final class SessionDuration
{
    /**
     * A session still running has no length yet and shows the same dash as
     * a missing check-out, rather than a misleading zero.
     */
    public static function format(?int $seconds): string
    {
        if ($seconds === null) {
            return __('attendance.page.not_recorded');
        }

        $minutes = intdiv(max($seconds, 0), 60);
        $hours = intdiv($minutes, 60);

        if ($hours === 0) {
            return __('attendance.units.duration_minutes', ['minutes' => (string) $minutes]);
        }

        return __('attendance.units.duration', [
            'hours' => (string) $hours,
            'minutes' => (string) ($minutes % 60),
        ]);
    }
}
