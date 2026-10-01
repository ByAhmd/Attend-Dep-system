<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AttendanceRejectionReason;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\AttendanceSettings\Pages\EditAttendanceSetting;
use App\Models\AttendanceSetting;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceWorkflow;
use App\Services\Attendance\WorkingCalendar;
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
 *
 * The monthly correction allowance is the third value on this screen and
 * the one with a boundary worth stating: zero is a real setting that
 * switches correction requests off, so the form must accept it as readily
 * as it accepts three, and must refuse a negative for the same reason it
 * refuses thirty-two - both are numbers no month can mean.
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
    public function the_correction_allowance_is_on_the_form_with_the_configured_default(): void
    {
        Livewire::test(EditAttendanceSetting::class)
            ->assertSchemaStateSet([
                'correction_requests_per_month' => (int) config('attendance.default_correction_requests_per_month'),
            ])
            ->assertSee(__('settings.sections.corrections'))
            // The sentence that says what zero means, on the screen that
            // can set it - not only in a docblock.
            ->assertSee(__('settings.helpers.correction_requests_per_month'));
    }

    #[Test]
    public function the_correction_allowance_can_be_changed_from_the_panel(): void
    {
        $this->configureCompanyLocation();

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => 7])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('settings.notifications.saved'));

        $this->assertSame(7, AttendanceSetting::current()->correction_requests_per_month);
    }

    #[Test]
    public function both_ends_of_the_allowance_are_accepted(): void
    {
        $this->configureCompanyLocation();

        $bounds = config('attendance.correction_quota_bounds');

        // Zero is not an empty box: it switches correction requests off.
        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => $bounds['min']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, AttendanceSetting::current()->correction_requests_per_month);

        // One request for every day of the longest month.
        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => $bounds['max']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(31, AttendanceSetting::current()->correction_requests_per_month);
    }

    #[Test]
    public function an_allowance_outside_the_bounds_is_refused_in_the_settings_words(): void
    {
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(3);

        $bounds = config('attendance.correction_quota_bounds');

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => $bounds['max'] + 1])
            ->call('save')
            ->assertHasFormErrors(['correction_requests_per_month' => 'max'])
            ->assertSee(__('settings.validation.correction_quota_max', ['max' => $bounds['max']]));

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => -1])
            ->call('save')
            ->assertHasFormErrors(['correction_requests_per_month' => 'min'])
            ->assertSee(__('settings.validation.correction_quota_min'));

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => 2.5])
            ->call('save')
            ->assertHasFormErrors(['correction_requests_per_month' => 'integer'])
            ->assertSee(__('settings.validation.correction_quota_integer'));

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['correction_requests_per_month' => ''])
            ->call('save')
            ->assertHasFormErrors(['correction_requests_per_month' => 'required'])
            ->assertSee(__('settings.validation.correction_quota_required'));

        // Nothing was written by any of the four refusals.
        $this->assertSame(3, AttendanceSetting::current()->correction_requests_per_month);
    }

    #[Test]
    public function the_working_day_is_on_the_form_with_the_briefs_defaults(): void
    {
        // The pickers hide seconds, so their state is the five-character
        // wall-clock time whatever the TIME column returned.
        Livewire::test(EditAttendanceSetting::class)
            ->assertSchemaStateSet([
                'work_starts_at' => '09:00',
                'work_ends_at' => '17:00',
                'late_grace_minutes' => (int) config('attendance.default_late_grace_minutes'),
            ])
            ->assertSee(__('settings.sections.working_hours'))
            // The sentence that says what the three values DO - marked
            // late after the grace, a reason asked before the end - on the
            // screen that can change them.
            ->assertSee(__('settings.helpers.working_hours'));
    }

    #[Test]
    public function the_working_day_can_be_changed_and_the_workflow_reads_the_change(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock('2026-09-02 15:30:00');

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'work_starts_at' => '08:00',
                'work_ends_at' => '15:00',
                'late_grace_minutes' => 10,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('settings.notifications.saved'));

        $workingHours = AttendanceSetting::current()->workingHours();

        $this->assertSame(10, $workingHours->lateGraceMinutes);

        // 15:30 was an early check-out against the 17:00 day; against the
        // day just saved it is an ordinary one, and the workflow accepts
        // it without a reason.
        $employee = $this->makeEmployee();
        $this->checkedIn($employee);

        $attendance = app(AttendanceWorkflow::class)->checkOut($employee, $this->readingAtCompany());

        $this->assertFalse($attendance->isOpen());
        $this->assertNull($attendance->early_check_out_reason);
    }

    #[Test]
    public function a_day_that_ends_before_it_starts_is_refused_in_the_settings_words(): void
    {
        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'work_starts_at' => '17:00',
                'work_ends_at' => '09:00',
            ])
            ->call('save')
            ->assertHasFormErrors(['work_ends_at' => 'after'])
            ->assertSee(__('settings.validation.working_day_ordered'));

        $this->assertSame('09:00:00', AttendanceSetting::current()->work_starts_at);
    }

    #[Test]
    public function a_grace_outside_the_bounds_is_refused(): void
    {
        $bounds = config('attendance.late_grace_bounds');

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['late_grace_minutes' => $bounds['max'] + 1])
            ->call('save')
            ->assertHasFormErrors(['late_grace_minutes' => 'max'])
            ->assertSee(__('settings.validation.late_grace', ['min' => $bounds['min'], 'max' => $bounds['max']]));

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['late_grace_minutes' => -1])
            ->call('save')
            ->assertHasFormErrors(['late_grace_minutes' => 'min']);

        $this->assertSame(
            (int) config('attendance.default_late_grace_minutes'),
            AttendanceSetting::current()->late_grace_minutes,
        );
    }

    #[Test]
    public function the_weekend_and_the_annual_allowance_are_on_the_form_with_their_defaults(): void
    {
        Livewire::test(EditAttendanceSetting::class)
            ->assertSchemaStateSet([
                'weekend_days' => ['friday', 'saturday'],
                'annual_leave_days' => (int) config('attendance.default_annual_leave_days'),
            ])
            ->assertSee(__('settings.sections.annual_leave'))
            ->assertSee(__('settings.helpers.weekend_days'));
    }

    #[Test]
    public function the_weekend_can_be_changed_and_the_calendar_reads_the_change(): void
    {
        // 2026-09-06 is a Sunday: a working day under the default weekend,
        // and the weekend itself once the owner says so.
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock('2026-09-06 10:00:00');
        $today = app(AttendanceCalendar::class)->today();

        $this->assertTrue(app(WorkingCalendar::class)->isWorkingDay($today));

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['weekend_days' => ['sunday']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['sunday'], AttendanceSetting::current()->weekend_days);
        $this->assertFalse(app(WorkingCalendar::class)->isWorkingDay($today));
    }

    #[Test]
    public function a_weekend_of_all_seven_days_is_refused(): void
    {
        $this->configureCompanyLocation();

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm([
                'weekend_days' => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            ])
            ->call('save')
            ->assertHasFormErrors(['weekend_days' => 'max'])
            ->assertSee(__('settings.validation.weekend_days_max'));

        $this->assertSame(['friday', 'saturday'], AttendanceSetting::current()->weekend_days);
    }

    #[Test]
    public function the_annual_allowance_can_be_changed_within_its_bounds(): void
    {
        $this->configureCompanyLocation();

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['annual_leave_days' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(30, AttendanceSetting::current()->annual_leave_days);

        $bounds = config('attendance.annual_leave_bounds');

        Livewire::test(EditAttendanceSetting::class)
            ->fillForm(['annual_leave_days' => $bounds['max'] + 1])
            ->call('save')
            ->assertHasFormErrors(['annual_leave_days' => 'max'])
            ->assertSee(__('settings.validation.annual_leave', ['min' => $bounds['min'], 'max' => $bounds['max']]));

        $this->assertSame(30, AttendanceSetting::current()->annual_leave_days);
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
