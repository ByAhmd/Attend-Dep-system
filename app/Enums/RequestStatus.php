<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a request stands: nobody has answered it, somebody said yes, or
 * somebody said no.
 *
 * Shared by attendance corrections and leave requests on purpose. The three
 * states are identical, they are written into the two tables as the same
 * CHECK-constraint string, and one enum means one entry in the translation
 * parity test's pinned list instead of two lists that drift apart the first
 * time somebody adds a state to one of them.
 *
 * There is no Cancelled: a request an employee regrets is rejected by an
 * administrator with a note, so every ending carries a name and a reason.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.request_status.'.$this->value);
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Filament badge colour. Amber means somebody must act, which is
     * precisely what a pending request is.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * The heroicon name, as a string rather than a Heroicon case, so this
     * enum carries no dependency on Filament and stays usable from a
     * service, a notification or a test.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Approved => 'heroicon-o-check-circle',
            self::Rejected => 'heroicon-o-x-circle',
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
