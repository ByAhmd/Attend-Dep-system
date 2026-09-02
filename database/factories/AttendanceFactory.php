<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An attendance record as the workflow would have written it: today's date,
 * a morning check-in a few metres from a Riyadh company location, no
 * check-out until checkedOut() is applied.
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
                'check_out_latitude' => self::COMPANY_LATITUDE - 0.00005,
                'check_out_longitude' => self::COMPANY_LONGITUDE,
                'check_out_accuracy' => 9.8,
                'check_out_distance_from_company' => 5.56,
            ];
        });
    }
}
