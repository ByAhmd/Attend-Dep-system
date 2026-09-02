<?php

declare(strict_types=1);

namespace App\Exceptions\Attendance;

use App\Data\Attendance\LocationVerification;
use App\Enums\AttendanceRejectionReason;
use RuntimeException;

/**
 * A check-in or check-out that the rules refused.
 *
 * Carries the reason as an enum so callers branch on the rule, not the
 * text, and the verification (when one was made) so the interface can show
 * the employee how far away they were.
 */
final class AttendanceRejectedException extends RuntimeException
{
    public function __construct(
        public readonly AttendanceRejectionReason $reason,
        public readonly ?LocationVerification $verification = null,
    ) {
        parent::__construct($reason->message($verification));
    }
}
