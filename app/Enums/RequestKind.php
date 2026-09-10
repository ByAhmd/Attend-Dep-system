<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two things an employee may ask an administrator for.
 *
 * Not a column anywhere: `attendance_corrections` and `leave_requests` are
 * two tables and each row's kind is the table it is in. This enum exists for
 * the one place that has to hold both at once - a notification, which is
 * stored as data and has to say later which queue it came from.
 *
 * icon() returns a heroicon name as a string for the same reason
 * RequestStatus::icon() does: an enum that imported Filament could not be
 * read from a service or a test without it.
 *
 * There is no options() and no label(), and both absences are deliberate.
 * Nothing chooses a kind from a select - the form an employee opens already
 * knows which of the two it is - and nothing prints a kind on its own
 * either: a line in the bell says "New correction request", which is a
 * sentence in lang/notifications.php and not a noun with a word bolted onto
 * it. A label() here would be two translations nothing reads, and it would
 * put this enum in the parity test's glob for no reason, exactly as
 * CorrectionRefusalReason and LeaveRefusalReason stay out of it.
 */
enum RequestKind: string
{
    case Correction = 'correction';

    case Leave = 'leave';

    /**
     * The glyph the admin panel already uses for this kind of request, so a
     * line in the bell and the sidebar entry it opens carry the same shape.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Correction => 'heroicon-o-pencil-square',
            self::Leave => 'heroicon-o-calendar-days',
        };
    }
}
