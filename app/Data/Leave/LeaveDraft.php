<?php

declare(strict_types=1);

namespace App\Data\Leave;

use App\Enums\LeaveType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the employee stated on the leave form.
 *
 * The range is inclusive at both ends, exactly as the employee reads it: a
 * single day has the same date twice. Both dates are days and never
 * moments, because leave is agreed in days and an hour on either end of one
 * would be a promise the system cannot keep.
 *
 * The supporting document travels as one group of four values or as none at
 * all. The table enforces the same rule with a CHECK, and the reason it is
 * a rule rather than four independent columns is that three of them
 * describe the fourth: a path with no name and no size is a file nobody can
 * be shown, and a name with no path is a file that is not there.
 */
final readonly class LeaveDraft
{
    public function __construct(
        public LeaveType $type,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public bool $isExitAndReturn,
        public string $reason,
        public ?string $attachmentPath = null,
        public ?string $attachmentName = null,
        public ?int $attachmentSize = null,
        public ?string $attachmentMimeType = null,
    ) {
        $present = array_filter(
            [$attachmentPath, $attachmentName, $attachmentSize, $attachmentMimeType],
            static fn (int|string|null $value): bool => $value !== null,
        );

        if ($present !== [] && count($present) !== 4) {
            throw new InvalidArgumentException(
                'A leave attachment is described by its path, name, size and MIME type together, or by none of them.',
            );
        }
    }

    public function hasAttachment(): bool
    {
        return $this->attachmentPath !== null;
    }

    /**
     * Calendar days covered, both ends included.
     */
    public function dayCount(): int
    {
        return (int) $this->startsOn->diffInDays($this->endsOn) + 1;
    }

    /**
     * Reads a Filament form payload.
     *
     * "Leaving and coming back" collapses the range to the single day it
     * describes. The form hides the end picker when the toggle is on - an
     * end date with one legal value is not a control - so the payload may
     * still carry whatever was chosen before the toggle was pressed, and
     * the honest reading of the two together is the one day the employee
     * meant.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFormData(array $data): self
    {
        $startsOn = self::day($data['starts_on'] ?? null);
        $isExitAndReturn = (bool) ($data['is_exit_and_return'] ?? false);

        $endsOn = $isExitAndReturn
            ? $startsOn
            : self::day($data['ends_on'] ?? $data['starts_on'] ?? null);

        return new self(
            type: self::type($data['type'] ?? null),
            startsOn: $startsOn,
            endsOn: $endsOn,
            isExitAndReturn: $isExitAndReturn,
            reason: trim((string) ($data['reason'] ?? '')),
            attachmentPath: self::text($data['attachment_path'] ?? null),
            attachmentName: self::text($data['attachment_name'] ?? null),
            attachmentSize: self::optionalSize($data['attachment_size'] ?? null),
            attachmentMimeType: self::text($data['attachment_mime_type'] ?? null),
        );
    }

    private static function day(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->setTimezone(config('app.timezone'))->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('A leave draft needs the days it covers.');
        }

        return CarbonImmutable::parse($value, config('app.timezone'))->startOfDay();
    }

    private static function type(mixed $value): LeaveType
    {
        if ($value instanceof LeaveType) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A leave draft needs a type.');
        }

        return LeaveType::from($value);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function optionalSize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
