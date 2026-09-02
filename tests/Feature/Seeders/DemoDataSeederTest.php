<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\AttendanceRejectionReason;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Database\Factories\AttendanceFactory;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The local walkthrough data. The clock is frozen on a Wednesday so that
 * "yesterday" is a working day and the seven working days back from it
 * span exactly one Friday-Saturday weekend.
 */
final class DemoDataSeederTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const array ACTIVE_EMPLOYEES = ['sara@attendance.test', 'mohammed@attendance.test', 'nora@attendance.test'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_seeds_the_demo_accounts_with_the_documented_password(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseCount('users', 5);

        $admin = User::query()->where('email', 'admin@attendance.test')->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertTrue(Hash::check(DemoDataSeeder::PASSWORD, (string) $admin->getRawOriginal('password')));

        foreach (self::ACTIVE_EMPLOYEES as $email) {
            $employee = User::query()->where('email', $email)->firstOrFail();

            $this->assertSame(UserRole::Employee, $employee->role);
            $this->assertSame(UserStatus::Active, $employee->status);
            $this->assertTrue(Hash::check(DemoDataSeeder::PASSWORD, (string) $employee->getRawOriginal('password')));
        }

        $inactive = User::query()->where('email', 'khalid@attendance.test')->firstOrFail();

        $this->assertSame(UserRole::Employee, $inactive->role);
        $this->assertSame(UserStatus::Inactive, $inactive->status);
    }

    #[Test]
    public function it_configures_the_company_location_without_overwriting_one_already_set(): void
    {
        $this->seed(DemoDataSeeder::class);

        $settings = AttendanceSetting::current();

        $this->assertTrue($settings->isConfigured());
        $this->assertEqualsWithDelta(AttendanceFactory::COMPANY_LATITUDE, (float) $settings->latitude, 1e-7);
        $this->assertEqualsWithDelta(AttendanceFactory::COMPANY_LONGITUDE, (float) $settings->longitude, 1e-7);

        $this->configureCompanyLocation(latitude: 21.4858, longitude: 39.1925, radiusMeters: 300);

        $this->seed(DemoDataSeeder::class);

        $kept = AttendanceSetting::current();

        $this->assertEqualsWithDelta(21.4858, (float) $kept->latitude, 1e-7);
        $this->assertEqualsWithDelta(39.1925, (float) $kept->longitude, 1e-7);
        $this->assertSame(300, $kept->radius_meters);
    }

    #[Test]
    public function it_seeds_seven_working_days_for_every_active_employee_and_none_on_the_weekend(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseCount('attendances', 21);

        foreach (self::ACTIVE_EMPLOYEES as $email) {
            $employee = User::query()->where('email', $email)->firstOrFail();
            $days = Attendance::query()->where('user_id', $employee->id)->orderByDesc('attendance_date')->get();

            $this->assertCount(7, $days);
            $this->assertSame(
                ['2026-09-01', '2026-08-31', '2026-08-30', '2026-08-27', '2026-08-26', '2026-08-25', '2026-08-24'],
                $days->map(static fn (Attendance $attendance): string => $attendance->attendance_date->toDateString())->all(),
            );
        }

        foreach (Attendance::query()->get() as $attendance) {
            $this->assertFalse($attendance->attendance_date->isFriday(), 'attendance seeded on a Friday');
            $this->assertFalse($attendance->attendance_date->isSaturday(), 'attendance seeded on a Saturday');
        }

        $inactive = User::query()->where('email', 'khalid@attendance.test')->firstOrFail();

        $this->assertSame(0, Attendance::query()->where('user_id', $inactive->id)->count());
        $this->assertSame(0, Attendance::query()->forDate(Carbon::today())->count());
    }

    #[Test]
    public function it_leaves_exactly_one_record_open_on_yesterday_as_a_missing_check_out(): void
    {
        $this->seed(DemoDataSeeder::class);

        $open = Attendance::query()->open()->get();

        $this->assertCount(1, $open);

        $forgotten = $open->first();

        $this->assertInstanceOf(Attendance::class, $forgotten);
        $this->assertSame('2026-09-01', $forgotten->attendance_date->toDateString());
        $this->assertSame('sara@attendance.test', $forgotten->user->email);
        $this->assertSame(AttendanceStatus::MissingCheckOut, $forgotten->status());
    }

    #[Test]
    public function it_seeds_one_rejection_for_each_location_failure(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseCount('attendance_rejections', 2);

        $rejections = AttendanceRejection::query()->orderBy('created_at')->get();
        $sara = User::query()->where('email', 'sara@attendance.test')->firstOrFail();

        $this->assertSame(
            [AttendanceRejectionReason::OutsideAllowedArea, AttendanceRejectionReason::InsufficientAccuracy],
            $rejections->map(static fn (AttendanceRejection $rejection): AttendanceRejectionReason => $rejection->reason)->all(),
        );

        foreach ($rejections as $rejection) {
            $this->assertSame($sara->id, $rejection->user_id);
            $this->assertSame('2026-09-01', $rejection->created_at->toDateString());
        }
    }

    #[Test]
    public function it_can_be_run_twice_without_duplicating_anything(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('attendances', 21);
        $this->assertDatabaseCount('attendance_rejections', 2);
        $this->assertDatabaseCount('attendance_settings', 1);
    }

    #[Test]
    public function it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->run();

            $this->fail('DemoDataSeeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must never run in production', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('attendances', 0);
    }
}
