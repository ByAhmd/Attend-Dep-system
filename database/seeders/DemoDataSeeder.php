<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Plausible data for a local walkthrough: an administrator, a few
 * employees, a week of attendance around a Riyadh company location, and a
 * couple of refused attempts. Every account's password is "password".
 *
 * Refuses to run in production - it would plant known credentials.
 */
final class DemoDataSeeder extends Seeder
{
    public const string PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder plants known credentials and must never run in production.');
        }

        $this->configureCompanyLocation();

        $this->account('مدير النظام', 'admin@attendance.test', UserRole::Admin);

        $employees = [
            $this->account('سارة الحربي', 'sara@attendance.test'),
            $this->account('محمد القحطاني', 'mohammed@attendance.test'),
            $this->account('نورة الدوسري', 'nora@attendance.test'),
            $this->account('خالد العتيبي', 'khalid@attendance.test', status: UserStatus::Inactive),
        ];

        $this->seedWeekOfAttendance(array_slice($employees, 0, 3));
        $this->seedRejections($employees[0]);

        $this->command->info('Demo data seeded. Sign in with admin@attendance.test / password (admin) or sara@attendance.test / password (employee).');
    }

    private function configureCompanyLocation(): void
    {
        $settings = AttendanceSetting::current();

        if ($settings->isConfigured()) {
            return;
        }

        $settings->forceFill([
            'latitude' => AttendanceFactory::COMPANY_LATITUDE,
            'longitude' => AttendanceFactory::COMPANY_LONGITUDE,
        ])->save();
    }

    private function account(
        string $name,
        string $email,
        UserRole $role = UserRole::Employee,
        UserStatus $status = UserStatus::Active,
    ): User {
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $existing;
        }

        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => $status,
        ]);
    }

    /**
     * Seven working days back from yesterday, skipping the Saudi weekend.
     * Yesterday's record for the first employee is left open so the
     * "missing check-out" state is visible.
     *
     * @param  list<User>  $employees
     */
    private function seedWeekOfAttendance(array $employees): void
    {
        $day = app(AttendanceCalendar::class)->today()->subDay();
        $workingDays = 0;

        while ($workingDays < 7) {
            if (! $this->isWeekend($day)) {
                foreach ($employees as $index => $employee) {
                    $this->attendanceOn($day, $employee, $index, leaveOpen: $workingDays === 0 && $index === 0);
                }

                $workingDays++;
            }

            $day = $day->subDay();
        }
    }

    private function attendanceOn(CarbonImmutable $day, User $employee, int $index, bool $leaveOpen): void
    {
        if (Attendance::query()->where('user_id', $employee->id)->forDate($day)->exists()) {
            return;
        }

        // Deterministic spread: each employee a few metres from the entrance.
        $offset = 0.00004 * ($index + 1);

        $attributes = [
            'user_id' => $employee->id,
            'attendance_date' => $day->toDateString(),
            'check_in_at' => $day->setTime(7, 50 + ($index * 7)),
            'check_in_latitude' => AttendanceFactory::COMPANY_LATITUDE + $offset,
            'check_in_longitude' => AttendanceFactory::COMPANY_LONGITUDE,
            'check_in_accuracy' => 8.0 + ($index * 3),
            'check_in_distance_from_company' => round($offset * 111_195, 2),
        ];

        if (! $leaveOpen) {
            $attributes += [
                'check_out_at' => $day->setTime(16, 58 + ($index * 5)),
                'check_out_latitude' => AttendanceFactory::COMPANY_LATITUDE - $offset,
                'check_out_longitude' => AttendanceFactory::COMPANY_LONGITUDE,
                'check_out_accuracy' => 6.0 + ($index * 2),
                'check_out_distance_from_company' => round($offset * 111_195, 2),
            ];
        }

        Attendance::query()->create($attributes);
    }

    private function seedRejections(User $employee): void
    {
        if (AttendanceRejection::query()->where('user_id', $employee->id)->exists()) {
            return;
        }

        $yesterday = app(AttendanceCalendar::class)->today()->subDay();

        // forceCreate: created_at is deliberately not fillable on the model,
        // and these rows must carry yesterday's timestamps to be plausible.
        AttendanceRejection::query()->forceCreate([
            'user_id' => $employee->id,
            'action' => AttendanceAction::CheckIn,
            'latitude' => AttendanceFactory::COMPANY_LATITUDE + 0.02,
            'longitude' => AttendanceFactory::COMPANY_LONGITUDE,
            'accuracy' => 15.0,
            'distance_from_company' => 2223.9,
            'reason' => AttendanceRejectionReason::OutsideAllowedArea,
            'created_at' => $yesterday->setTime(7, 31),
        ]);

        AttendanceRejection::query()->forceCreate([
            'user_id' => $employee->id,
            'action' => AttendanceAction::CheckIn,
            'latitude' => AttendanceFactory::COMPANY_LATITUDE,
            'longitude' => AttendanceFactory::COMPANY_LONGITUDE,
            'accuracy' => 650.0,
            'distance_from_company' => 0.0,
            'reason' => AttendanceRejectionReason::InsufficientAccuracy,
            'created_at' => $yesterday->setTime(7, 44),
        ]);
    }

    private function isWeekend(CarbonImmutable $day): bool
    {
        return $day->isFriday() || $day->isSaturday();
    }
}
