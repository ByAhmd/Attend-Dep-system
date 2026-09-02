<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AttendanceRejectionReason;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\AttendanceSettings\Pages\EditAttendanceSetting;
use App\Models\AttendanceSetting;
use App\Services\Attendance\AttendanceWorkflow;
use Database\Factories\AttendanceFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * AttendanceSettingResource: the single-row settings screen.
 *
 * What matters is not that the form saves, but that the workflow reads
 * what it saved: a radius changed here must move the line the next
 * check-in is judged against.
 */
final class AttendanceSettingsTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin());
    }

    #[Test]
    public function the_page_renders_with_the_current_values(): void
    {
        $this->configureCompanyLocation(24.7136, 46.6753, radiusMeters: 200);

        Livewire::test(EditAttendanceSetting::class)
            ->assertSchemaStateSet([
                'latitude' => '24.7136000',
                'longitude' => '46.6753000',
                'radius_meters' => 200,
            ])
            ->assertDontSee(__('settings.helpers.not_configured'))
            ->assertOk();
    }

    #[Test]
    public function a_fresh_install_shows_the_default_radius_of_150_and_a_warning(): void
    {
        Livewire::test(EditAttendanceSetting::class)
            ->assertSchemaStateSet([
                'latitude' => null,
                'longitude' => null,
                'radius_meters' => 150,
            ])
            ->assertSee(__('settings.helpers.not_configured'))
            ->assertActionHidden('preview')
            ->assertOk();
    }

    #[Test]
    public function saving_updates_the_location_and_the_workflow_respects_it(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'latitude' => AttendanceFactory::COMPANY_LATITUDE,
                'longitude' => AttendanceFactory::COMPANY_LONGITUDE,
                'radius_meters' => 100,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('settings.notifications.saved'));

        $settings = AttendanceSetting::current();

        $this->assertSame('24.7136000', $settings->latitude);
        $this->assertSame('46.6753000', $settings->longitude);
        $this->assertSame(100, $settings->radius_meters);

        // 120 m was inside the default 150 m radius; it is outside the
        // 100 m just saved.
        try {
            app(AttendanceWorkflow::class)->checkIn($employee, $this->readingMetersFromCompany(120));

            $this->fail('A reading beyond the saved radius was accepted.');
        } catch (AttendanceRejectedException $exception) {
            $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $exception->reason);
            $this->assertSame(100, $exception->verification?->allowedRadiusMeters);
        }

        $attendance = app(AttendanceWorkflow::class)->checkIn($employee, $this->readingMetersFromCompany(80));

        $this->assertSame('80.00', $attendance->check_in_distance_from_company);
    }

    #[Test]
    public function the_map_preview_appears_once_the_location_is_configured(): void
    {
        $this->configureCompanyLocation(24.7136, 46.6753);

        Livewire::test(EditAttendanceSetting::class)
            ->assertActionVisible('preview')
            ->assertActionHasUrl('preview', 'https://www.google.com/maps?q=24.7136000,46.6753000')
            ->assertActionShouldOpenUrlInNewTab('preview');
    }

    #[Test]
    public function out_of_range_values_fail_validation_with_the_settings_messages(): void
    {
        $bounds = config('attendance.radius_bounds');

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'latitude' => 95,
                'longitude' => -200,
                'radius_meters' => $bounds['min'] - 1,
            ])
            ->call('save')
            ->assertHasFormErrors([
                'latitude' => 'between',
                'longitude' => 'between',
                'radius_meters' => 'min',
            ])
            ->assertSee(__('settings.validation.latitude'))
            ->assertSee(__('settings.validation.longitude'))
            ->assertSee(__('settings.validation.radius', ['min' => $bounds['min'], 'max' => $bounds['max']]));

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'latitude' => 24.7136,
                'longitude' => 46.6753,
                'radius_meters' => $bounds['max'] + 1,
            ])
            ->call('save')
            ->assertHasFormErrors(['radius_meters' => 'max']);

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'latitude' => 24.7136,
                'longitude' => 46.6753,
                'radius_meters' => 150.5,
            ])
            ->call('save')
            ->assertHasFormErrors(['radius_meters' => 'integer']);

        $this->assertFalse(AttendanceSetting::current()->isConfigured());
    }

    #[Test]
    public function the_settings_row_is_never_created_or_deleted_from_the_panel(): void
    {
        $this->assertFalse(AttendanceSettingResource::canCreate());
        $this->assertSame(['index'], array_keys(AttendanceSettingResource::getPages()));

        Livewire::test(EditAttendanceSetting::class)
            ->assertActionDoesNotExist('delete');
    }

    #[Test]
    public function an_employee_is_refused_the_settings_over_http(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($employee)->get('/admin/attendance-settings')->assertForbidden();
    }

    #[Test]
    public function an_administrator_reaches_the_settings_over_http(): void
    {
        $this->get('/admin/attendance-settings')->assertOk();
    }
}
