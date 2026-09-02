<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Enums\AttendanceRejectionReason;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Models\AttendanceSetting;
use App\Services\Attendance\AttendanceWorkflow;
use App\Services\Attendance\LocationVerifier;
use Carbon\Carbon;
use Database\Factories\AttendanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Where the numbers come from: the default radius and the accuracy ceiling
 * in config, the live location and radius in the single settings row, and
 * the frozen timezone and stack in the files a deployment is built from.
 */
final class AttendanceSettingsTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_default_radius_is_the_150_metres_fixed_by_the_brief(): void
    {
        $this->assertSame(150, config('attendance.default_radius_meters'));
    }

    #[Test]
    public function the_settings_row_is_created_on_first_use_with_the_default_radius_and_no_location(): void
    {
        $this->assertDatabaseCount('attendance_settings', 0);

        $settings = AttendanceSetting::current();

        $this->assertSame(AttendanceSetting::SINGLETON_ID, $settings->id);
        $this->assertSame(150, $settings->radius_meters);
        $this->assertNull($settings->latitude);
        $this->assertNull($settings->longitude);
        $this->assertFalse($settings->isConfigured());
        $this->assertNull($settings->coordinates());
        $this->assertDatabaseCount('attendance_settings', 1);
    }

    #[Test]
    public function current_always_returns_the_same_single_row(): void
    {
        $first = AttendanceSetting::current();
        $second = AttendanceSetting::current();

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('attendance_settings', 1);
    }

    #[Test]
    public function the_location_counts_as_configured_only_with_both_coordinates(): void
    {
        $settings = AttendanceSetting::current();

        $settings->forceFill(['latitude' => AttendanceFactory::COMPANY_LATITUDE])->save();
        $this->assertFalse($settings->fresh()?->isConfigured());

        $settings->forceFill(['latitude' => null, 'longitude' => AttendanceFactory::COMPANY_LONGITUDE])->save();
        $this->assertFalse($settings->fresh()?->isConfigured());

        $settings->forceFill(['latitude' => AttendanceFactory::COMPANY_LATITUDE])->save();

        $configured = $settings->fresh();

        $this->assertInstanceOf(AttendanceSetting::class, $configured);
        $this->assertTrue($configured->isConfigured());
        $this->assertEqualsWithDelta(AttendanceFactory::COMPANY_LATITUDE, $configured->coordinates()?->latitude, 1e-7);
        $this->assertEqualsWithDelta(AttendanceFactory::COMPANY_LONGITUDE, $configured->coordinates()?->longitude, 1e-7);
    }

    #[Test]
    public function a_changed_radius_is_what_the_workflow_enforces(): void
    {
        $this->configureCompanyLocation();
        $workflow = app(AttendanceWorkflow::class);
        $reading = $this->readingMetersFromCompany(180.0);

        try {
            $workflow->checkIn($this->makeEmployee(), $reading);

            $this->fail('180 m was accepted under the default 150 m radius.');
        } catch (AttendanceRejectedException $rejection) {
            $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $rejection->reason);
        }

        AttendanceSetting::current()->forceFill(['radius_meters' => 200])->save();

        $attendance = $workflow->checkIn($this->makeEmployee(), $reading);

        $this->assertSame('180.00', $attendance->check_in_distance_from_company);
    }

    #[Test]
    public function changed_coordinates_are_what_the_verifier_measures_from(): void
    {
        $verifier = app(LocationVerifier::class);
        $reading = $this->readingAtCompany();

        $this->configureCompanyLocation();

        $this->assertEqualsWithDelta(0.0, $verifier->verify($reading)->distanceMeters, 0.01);

        $this->configureCompanyLocation(latitude: $this->coordinatesMetersFromCompany(100.0)->latitude);

        $this->assertEqualsWithDelta(100.0, $verifier->verify($reading)->distanceMeters, 0.01);

        $this->configureCompanyLocation(longitude: $this->coordinatesMetersEastOfCompany(120.0)->longitude);

        $this->assertEqualsWithDelta(120.0, $verifier->verify($reading)->distanceMeters, 0.01);
    }

    #[Test]
    public function the_accuracy_ceiling_under_test_is_100_metres(): void
    {
        $this->assertSame(100, config('attendance.max_accuracy_meters'));
    }

    #[Test]
    public function the_application_timezone_is_riyadh_and_defaults_to_it_in_config(): void
    {
        $this->assertSame('Asia/Riyadh', config('app.timezone'));

        $source = file_get_contents(base_path('config/app.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("env('APP_TIMEZONE', 'Asia/Riyadh')", $source);
    }

    #[Test]
    public function the_environment_template_carries_the_settings_a_deployment_must_know(): void
    {
        $template = file_get_contents(base_path('.env.example'));

        $this->assertIsString($template);
        $this->assertMatchesRegularExpression('/^APP_TIMEZONE=Asia\/Riyadh$/m', $template);
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=mysql$/m', $template);
        $this->assertMatchesRegularExpression('/^ATTENDANCE_MAX_ACCURACY_METERS=/m', $template);
    }

    #[Test]
    public function the_stack_is_frozen_to_php_8_3_laravel_filament_and_tinker(): void
    {
        $contents = file_get_contents(base_path('composer.json'));

        $this->assertIsString($contents);

        $composer = json_decode($contents, true);

        $this->assertIsArray($composer);
        $this->assertIsArray($composer['require']);
        $this->assertSame('^8.3', $composer['require']['php']);
        $this->assertEqualsCanonicalizing(
            ['php', 'filament/filament', 'laravel/framework', 'laravel/tinker'],
            array_keys($composer['require']),
        );
    }
}
