<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\Attendance\LocationVerification;
use App\Support\Geo\Meters;

/**
 * Why a check-in or check-out was refused.
 *
 * Every rule of the attendance workflow maps to exactly one case, so the
 * employee always reads a sentence written for their situation and a test
 * can assert the rule that fired rather than parse a message.
 */
enum AttendanceRejectionReason: string
{
    case LocationNotConfigured = 'location_not_configured';
    case InactiveAccount = 'inactive_account';
    case AlreadyCheckedIn = 'already_checked_in';
    case NotCheckedIn = 'not_checked_in';
    case AlreadyCheckedOut = 'already_checked_out';
    case InsufficientAccuracy = 'insufficient_accuracy';
    case OutsideAllowedArea = 'outside_allowed_area';

    public function label(): string
    {
        return __('enums.attendance_rejection_reason.'.$this->value);
    }

    /**
     * The sentence shown to the employee. Distance, radius and accuracy are
     * substituted when the verification that produced the rejection is known.
     *
     * The distance and the accuracy are the quantities being refused for
     * being too large, so they are rounded up: a reading refused at 150.3 m
     * reads "151 m", never "150 m" beside "within 150 m".
     */
    public function message(?LocationVerification $verification = null): string
    {
        return __('attendance.rejections.'.$this->value, [
            'distance' => Meters::formatUp($verification?->roundedDistance()),
            'radius' => (string) ($verification->allowedRadiusMeters ?? config('attendance.default_radius_meters')),
            'accuracy' => $verification === null ? '—' : Meters::formatUp($verification->accuracyMeters),
            'max_accuracy' => Meters::format($verification->maxAccuracyMeters ?? (float) config('attendance.max_accuracy_meters')),
        ]);
    }

    /**
     * Whether a rejection for this reason is written to the audit table.
     *
     * Only the location failures are worth a row: they are what an
     * administrator needs when an employee reports that check-in "did not
     * work", and they are the only signal of someone trying from elsewhere.
     * A duplicate click that trips a state rule is noise, not evidence.
     */
    public function isRecorded(): bool
    {
        return in_array($this, [self::InsufficientAccuracy, self::OutsideAllowedArea], strict: true);
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
