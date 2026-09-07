<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One attendance session as the workflow would have written it: today's
 * date, a morning check-in a few metres from a Riyadh company location, no
 * check-out until checkedOut() is applied.
 *
 * A day may hold several sessions, so session() places one at the exact
 * moments a test names; on() and checkedOut() keep the single-session
 * shorthand for the many tests that only need "a day someone worked".
 *
 * @extends Factory<Attendance>
 */
final class AttendanceFactory extends Factory
{
    public const float COMPANY_LATITUDE = 24.7136;

    public const float COMPANY_LONGITUDE = 46.6753;

    public function definition(): array
    {
        $today = app(AttendanceCalendar::class)->today();

        return [
            'user_id' => User::factory(),
            'attendance_date' => $today->toDateString(),
            'check_in_at' => $today->setTime(8, 2),
            'check_in_latitude' => self::COMPANY_LATITUDE + 0.00005,
            'check_in_longitude' => self::COMPANY_LONGITUDE,
            'check_in_accuracy' => 12.5,
            'check_in_distance_from_company' => 5.56,
        ];
    }

    /**
     * Move the record to another day, keeping the same clock times. A
     * check-out already applied moves with it, so the two states compose in
     * either order and never leave a record closed on a different day than
     * it was opened.
     */
    public function on(CarbonInterface $date): static
    {
        return $this->state(function (array $attributes) use ($date): array {
            $state = [
                'attendance_date' => $date->toDateString(),
                'check_in_at' => $date->copy()->setTime(8, 2),
            ];

            if (isset($attributes['check_out_at'])) {
                $state['check_out_at'] = $date->copy()->setTime(17, 4);
            }

            return $state;
        });
    }

    public function checkedOut(): static
    {
        return $this->state(function (array $attributes): array {
            $checkInAt = $attributes['check_in_at'];

            return [
                'check_out_at' => $checkInAt->copy()->setTime(17, 4),
            ] + self::checkOutPosition();
        });
    }

    /**
     * One session at the exact moments given, closed only if a check-out
     * moment is given. The attendance day follows the check-in, the way the
     * workflow files it, so a test naming two sessions gets two rows on one
     * day without having to restate the date.
     */
    public function session(CarbonInterface $checkInAt, ?CarbonInterface $checkOutAt = null): static
    {
        return $this->state(function () use ($checkInAt, $checkOutAt): array {
            $state = [
                'attendance_date' => $checkInAt->toDateString(),
                'check_in_at' => $checkInAt,
            ];

            if ($checkOutAt instanceof CarbonInterface) {
                $state += ['check_out_at' => $checkOutAt] + self::checkOutPosition();
            }

            return $state;
        });
    }

    /**
     * Where the check-out was taken from: the same few metres from the
     * entrance as the check-in, on the other side of it.
     *
     * @return array<string, float>
     */
    private static function checkOutPosition(): array
    {
        return [
            'check_out_latitude' => self::COMPANY_LATITUDE - 0.00005,
            'check_out_longitude' => self::COMPANY_LONGITUDE,
            'check_out_accuracy' => 9.8,
            'check_out_distance_from_company' => 5.56,
        ];
    }
}
