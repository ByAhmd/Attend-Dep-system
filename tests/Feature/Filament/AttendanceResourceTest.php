<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * AttendanceResource: the read-only list of every check-in and check-out.
 *
 * Read-only is the point. The workflow is the only writer, so the resource
 * has one page and the create route must not exist at all - not be
 * forbidden, not exist.
 */
final class AttendanceResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin());
    }

    private function today(): CarbonImmutable
    {
        return app(AttendanceCalendar::class)->today();
    }

    #[Test]
    public function the_list_renders_with_the_employees_names(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $open = $this->checkedIn($sara);
        $closed = $this->checkedOut($omar);

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$open, $closed])
            ->assertSee($sara->name)
            ->assertSee($omar->name)
            ->assertSee(__('attendance.placeholders.no_check_out'))
            ->assertOk();
    }

    #[Test]
    public function the_employee_filter_shows_only_that_employees_records(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $hers = $this->checkedIn($sara);
        $his = $this->checkedIn($omar);

        Livewire::test(ListAttendances::class)
            ->filterTable('user_id', $sara->id)
            ->assertCanSeeTableRecords([$hers])
            ->assertCanNotSeeTableRecords([$his]);
    }

    #[Test]
    public function the_date_filter_narrows_the_list_to_one_day(): void
    {
        $sara = $this->makeEmployee();
        $yesterday = $this->today()->subDay();

        $todays = $this->checkedIn($sara);
        $yesterdays = $this->checkedOut($sara, $yesterday);

        Livewire::test(ListAttendances::class)
            ->filterTable('date', ['date' => $yesterday->toDateString()])
            ->assertCanSeeTableRecords([$yesterdays])
            ->assertCanNotSeeTableRecords([$todays]);
    }

    #[Test]
    public function the_date_range_filter_narrows_the_list_to_the_period(): void
    {
        $sara = $this->makeEmployee();
        $today = $this->today();

        $recent = $this->checkedIn($sara);
        $withinRange = $this->checkedOut($sara, $today->subDays(3));
        $tooOld = $this->checkedOut($sara, $today->subDays(10));

        Livewire::test(ListAttendances::class)
            ->filterTable('date_range', [
                'from' => $today->subDays(5)->toDateString(),
                'until' => $today->subDay()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$withinRange])
            ->assertCanNotSeeTableRecords([$recent, $tooOld]);
    }

    #[Test]
    public function the_view_action_shows_the_record_details(): void
    {
        $sara = $this->makeEmployee();
        $closed = $this->checkedOut($sara);

        // The factory places the check-in 0.00005 degrees north of the
        // company and the check-out the same distance south; the infolist
        // shows both at the seven decimals they are stored with.
        Livewire::test(ListAttendances::class)
            ->mountTableAction('view', $closed)
            ->assertMountedActionModalSee([
                __('attendance.sections.check_in'),
                __('attendance.sections.check_out'),
                '24.7136500, 46.6753000',
                '24.7135500, 46.6753000',
                __('attendance.admin_actions.open_map'),
            ]);
    }

    #[Test]
    public function the_view_action_omits_the_check_out_section_for_an_open_record(): void
    {
        $sara = $this->makeEmployee();
        $open = $this->checkedIn($sara);

        Livewire::test(ListAttendances::class)
            ->mountTableAction('view', $open)
            ->assertMountedActionModalSee(__('attendance.fields.check_in_location'))
            ->assertMountedActionModalDontSee(__('attendance.fields.check_out_location'));
    }

    #[Test]
    public function records_cannot_be_created_from_the_panel(): void
    {
        $this->assertFalse(AttendanceResource::canCreate());
        $this->assertSame(['index'], array_keys(AttendanceResource::getPages()));

        $this->get('/admin/attendances')->assertOk();
        $this->get('/admin/attendances/create')->assertNotFound();
    }

    #[Test]
    public function the_list_has_no_edit_or_delete_actions(): void
    {
        $sara = $this->makeEmployee();
        $this->checkedIn($sara);

        Livewire::test(ListAttendances::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }
}
