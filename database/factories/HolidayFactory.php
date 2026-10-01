<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Holiday;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One official holiday: a single day named in both languages, today unless
 * between() places it elsewhere. Deterministic names, because tests read
 * what the screens print.
 *
 * @extends Factory<Holiday>
 */
final class HolidayFactory extends Factory
{
    public function definition(): array
    {
        $today = app(AttendanceCalendar::class)->today();

        return [
            'name_ar' => 'اليوم الوطني',
            'name_en' => 'National Day',
            'starts_on' => $today->toDateString(),
            'ends_on' => $today->toDateString(),
        ];
    }

    /**
     * The inclusive range the holiday covers; one argument makes it a
     * single day, the way the fixtures read elsewhere.
     */
    public function between(CarbonInterface $from, ?CarbonInterface $until = null): static
    {
        return $this->state([
            'starts_on' => $from->toDateString(),
            'ends_on' => ($until ?? $from)->toDateString(),
        ]);
    }
}
