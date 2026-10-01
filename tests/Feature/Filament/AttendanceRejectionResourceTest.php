<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Filament\Resources\AttendanceRejections\AttendanceRejectionResource;
use App\Filament\Resources\AttendanceRejections\Pages\ListAttendanceRejections;
use App\Models\AttendanceRejection;
use App\Models\User;
use App\Services\Attendance\AttendanceWorkflow;
use App\Support\Geo\LocationReading;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * AttendanceRejectionResource: the read-only audit of refused attempts.
 *
 * Rows come from the real workflow rather than being inserted by hand, so
 * the list shows exactly what the system records - a far-away position
 * and a poor fix - and nothing it does not.
 */
final class AttendanceRejectionResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin());
        $this->configureCompanyLocation();
    }

    /**
     * Attempts a check-in the workflow will refuse and returns the audit
     * row it wrote for it.
     */
    private function rejectedCheckIn(User $employee, LocationReading $reading): AttendanceRejection
    {
        try {
            app(AttendanceWorkflow::class)->checkIn($employee, $reading);

            $this->fail('The check-in was expected to be rejected.');
        } catch (AttendanceRejectedException) {
        }

        return AttendanceRejection::query()
            ->where('user_id', $employee->id)
            ->latest('id')
            ->firstOrFail();
    }

    #[Test]
    public function the_list_renders_the_refused_attempts(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $farAway = $this->rejectedCheckIn($sara, $this->readingMetersFromCompany(500));
        $poorFix = $this->rejectedCheckIn($sara, $this->readingAtCompany(accuracyMeters: 250));

        Livewire::test(ListAttendanceRejections::class)
            ->assertCanSeeTableRecords([$farAway, $poorFix])
            ->assertSee($sara->name)
            ->assertSee(AttendanceRejectionReason::OutsideAllowedArea->label())
            ->assertSee(AttendanceRejectionReason::InsufficientAccuracy->label())
            ->assertSee(AttendanceAction::CheckIn->label())
            ->assertOk();
    }

    #[Test]
    public function the_reason_filter_shows_only_that_reason(): void
    {
        $sara = $this->makeEmployee();

        $farAway = $this->rejectedCheckIn($sara, $this->readingMetersFromCompany(500));
        $poorFix = $this->rejectedCheckIn($sara, $this->readingAtCompany(accuracyMeters: 250));

        Livewire::test(ListAttendanceRejections::class)
            ->filterTable('reason', AttendanceRejectionReason::OutsideAllowedArea)
            ->assertCanSeeTableRecords([$farAway])
            ->assertCanNotSeeTableRecords([$poorFix]);
    }

    #[Test]
    public function the_employee_filter_shows_only_that_employees_attempts(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $hers = $this->rejectedCheckIn($sara, $this->readingMetersFromCompany(500));
        $his = $this->rejectedCheckIn($omar, $this->readingMetersFromCompany(500));

        Livewire::test(ListAttendanceRejections::class)
            ->filterTable('user_id', $sara->id)
            ->assertCanSeeTableRecords([$hers])
            ->assertCanNotSeeTableRecords([$his]);
    }

    #[Test]
    public function the_action_filter_shows_only_that_action(): void
    {
        $sara = $this->makeEmployee();

        $checkIn = $this->rejectedCheckIn($sara, $this->readingMetersFromCompany(500));

        Livewire::test(ListAttendanceRejections::class)
            ->filterTable('action', AttendanceAction::CheckOut)
            ->assertCanNotSeeTableRecords([$checkIn])
            ->filterTable('action', AttendanceAction::CheckIn)
            ->assertCanSeeTableRecords([$checkIn]);
    }

    /**
     * The screen that replaced the map link. Where a refused attempt stood
     * is usually somebody's home; the audit stores no position, so this
     * list has no map to open and no coordinates anywhere in its markup.
     */
    #[Test]
    public function the_list_offers_no_map_and_prints_no_position(): void
    {
        $sara = $this->makeEmployee();

        $reading = $this->readingMetersFromCompany(500);
        $rejection = $this->rejectedCheckIn($sara, $reading);

        $component = Livewire::test(ListAttendanceRejections::class);

        $component
            ->assertOk()
            ->assertCanSeeTableRecords([$rejection])
            ->assertDontSee('google.com/maps');

        $html = $component->html();

        $this->assertStringNotContainsString(
            number_format($reading->coordinates->latitude, 7),
            $html,
            'A refused attempt\'s latitude reached the screen.',
        );
    }

    #[Test]
    public function the_audit_is_read_only(): void
    {
        $this->assertFalse(AttendanceRejectionResource::canCreate());
        $this->assertSame(['index'], array_keys(AttendanceRejectionResource::getPages()));

        $this->get('/admin/rejected-attempts')->assertOk();
        $this->get('/admin/rejected-attempts/create')->assertNotFound();
    }
}
