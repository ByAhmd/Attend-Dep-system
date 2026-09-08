<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an employee is asking for a recorded time to be changed.
 *
 * A closed list rather than a free-text field, so an administrator can
 * filter a queue by what actually goes wrong here and the answers stay
 * comparable across months. Two reasons other attendance products offer are
 * deliberately absent:
 *
 * - "a problem with the fingerprint device". There is no biometric hardware
 *   in this system, and a reason naming a machine that does not exist is an
 *   invitation to blame it. LocationProblem is the failure that really
 *   happens here - a phone that could not get a fix.
 * - "a shift swap". There are no shifts, so there is no shift to swap.
 *
 * There is no "other" either: within a month it becomes the answer to
 * everything and the column stops being worth filtering.
 */
enum CorrectionReason: string
{
    case ForgotToRecord = 'forgot_to_record';
    case LocationProblem = 'location_problem';
    case ApplicationProblem = 'application_problem';
    case ConnectionProblem = 'connection_problem';
    case RemoteWork = 'remote_work';
    case ExternalVisit = 'external_visit';
    case OvertimeAfterCheckOut = 'overtime_after_check_out';

    public function label(): string
    {
        return __('enums.correction_reason.'.$this->value);
    }

    /**
     * Whether the reason itself says the employee was not at the company.
     */
    public function awayFromCompany(): bool
    {
        return in_array($this, [self::RemoteWork, self::ExternalVisit], strict: true);
    }

    /**
     * Whether the reason asserts something no record can confirm, so the
     * note stops being optional.
     *
     * A forgotten tap or a failed fix leaves its own traces - an open
     * session, a rejected attempt, a gap in the pings. "I was working from
     * home" leaves none, and an approver with nothing to read is being
     * asked to take it on trust.
     */
    public function needsExplanation(): bool
    {
        return $this->awayFromCompany();
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
