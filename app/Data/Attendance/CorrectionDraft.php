<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use App\Enums\CorrectionReason;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the employee stated on the correction form, in the shape the domain
 * reads it.
 *
 * The two times are wall-clock strings and never moments. The employee's
 * phone knows what o'clock they mean and nothing else: the day is named
 * separately, and the server composes the two in Asia/Riyadh at approval,
 * which is the only place a timezone is applied. Carrying a composed
 * timestamp here would mean the browser had decided when the moment was,
 * and this system never lets a device decide a time.
 *
 * A draft asserts nothing about whether the request is allowed. Every rule
 * lives in AttendanceCorrectionWorkflow, so the form and a test can build
 * a draft that the workflow will refuse, and the refusal is the same one
 * either way.
 */
final readonly class CorrectionDraft
{
    public function __construct(
        public CarbonImmutable $date,
        public ?int $attendanceId,
        public CorrectionReason $reason,
        public ?string $checkInTime,
        public ?string $checkOutTime,
        public ?string $note,
    ) {}

    /**
     * Reads a Filament form payload.
     *
     * Every value arrives as the form's own state - a date string, a time
     * string, a select value - and is narrowed here rather than in the
     * page, so the page stays a page and one normalisation serves the form,
     * a test and anything else that ever fills this in.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFormData(array $data): self
    {
        return new self(
            date: self::day($data['attendance_date'] ?? null),
            attendanceId: self::optionalKey($data['attendance_id'] ?? null),
            reason: self::reason($data['reason'] ?? null),
            checkInTime: self::time($data['requested_check_in_at'] ?? null),
            checkOutTime: self::time($data['requested_check_out_at'] ?? null),
            note: self::text($data['note'] ?? null),
        );
    }

    /**
     * The day the request names, at midnight in the application timezone.
     */
    private static function day(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->setTimezone(config('app.timezone'))->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('A correction draft needs the day it is about.');
        }

        return CarbonImmutable::parse($value, config('app.timezone'))->startOfDay();
    }

    private static function reason(mixed $value): CorrectionReason
    {
        if ($value instanceof CorrectionReason) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A correction draft needs a reason.');
        }

        return CorrectionReason::from($value);
    }

    /**
     * 'HH:MM', whatever the picker sent.
     *
     * A TimePicker reports 'H:i:s' and a hand-filled payload may send
     * 'H:i'; the column is a TIME and the interface prints minutes, so both
     * are reduced to the same five characters and a test can assert the
     * string it typed.
     */
    private static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A requested time must be a wall-clock string.');
        }

        return CarbonImmutable::parse($value)->format('H:i');
    }

    private static function optionalKey(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * A note nobody typed is absent, not an empty string: the column is
     * nullable and "no note" is what the interface prints a placeholder for.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
