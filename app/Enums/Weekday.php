<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * A day of the week, for saying which days are the weekend.
 *
 * Values are Carbon's own English day names in lowercase, so a stored day
 * and a date being tested meet on the same spelling without a mapping
 * table in between. Case order is the week as this company reads it,
 * Sunday first, which is the order the settings form offers them in.
 */
enum Weekday: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';

    public static function ofDate(CarbonInterface $date): self
    {
        return self::from(strtolower($date->englishDayOfWeek));
    }

    public function label(): string
    {
        return __('enums.weekday.'.$this->value);
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
