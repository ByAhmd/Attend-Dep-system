<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * AttendanceResource: the read-only list of every session.
 *
 * A day an employee left and came back is several rows, so the list, the
 * filters, the grouping and the time-inside summary all have to keep
 * meaning something when one person appears three times on one date.
 *
 * Read-only is the point. AttendanceWorkflow is the only writer and
 * AttendanceCorrectionWorkflow the only amender, so the resource has one
 * page and the create route must not exist at all - not be forbidden, not
 * exist.
 *
 * An amended row is the other half of that promise. A time an approved
 * correction supplied must be distinguishable from one the location service
 * verified, everywhere it is printed and by three signals at once, or the
 * list stops being evidence of anything. The corrected cases here are
 * produced by the real workflow rather than written by hand, so what the
 * screen shows is what an approval actually leaves behind.
 */
final class AttendanceResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->admin = $this->makeAdmin();

        $this->actingAs($this->admin);
    }

    private function today(): CarbonImmutable
    {
        return app(AttendanceCalendar::class)->today();
    }

    /**
     * The day as an approved correction leaves it.
     *
     * Goes through the workflow rather than writing the columns, so a test
     * that reads the screen is reading the real result of an approval - and
     * the CHECK constraints on the table get a say in every fixture.
     */
    private function approve(AttendanceCorrection $request): Attendance
    {
        return app(AttendanceCorrectionWorkflow::class)->approve($request, $this->admin);
    }

    /**
     * One column of the list, bound to one row.
     *
     * The word a corrected time carries can be read out of the rendered
     * page, but its glyph and its colour cannot: both are drawn as inline
     * SVG and a colour token, so asking the column itself is the only way
     * to prove all three signals are there.
     */
    private function listColumn(string $name, Attendance $record): TextColumn
    {
        $page = Livewire::test(ListAttendances::class)->instance();

        if (! $page instanceof ListAttendances) {
            $this->fail('The attendance list did not mount.');
        }

        $column = $page->getTable()->getColumn($name);

        if (! $column instanceof TextColumn) {
            $this->fail("The attendance list has no [{$name}] text column.");
        }

        return $column->record($record);
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
    public function every_session_of_a_day_is_a_row_of_its_own_with_its_length(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $morning = $this->attendanceSession($sara, '08:00', '12:00');
        $afternoon = $this->attendanceSession($sara, '13:00', '17:30');
        $evening = $this->attendanceSession($sara, '19:00');

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$evening, $afternoon, $morning], inOrder: true)
            ->assertSee(__('attendance.fields.duration'))
            ->assertSee(__('attendance.units.duration', ['hours' => '4', 'minutes' => '0']))
            ->assertSee(__('attendance.units.duration', ['hours' => '4', 'minutes' => '30']))
            ->assertSee(__('attendance.placeholders.no_check_out'))
            ->assertOk();
    }

    #[Test]
    public function the_duration_summary_adds_up_the_time_inside_for_what_is_selected(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $this->attendanceSession($sara, '08:00', '12:00');
        $this->attendanceSession($sara, '13:00', '17:00');
        // Still inside: an open session has no length to add.
        $this->attendanceSession($sara, '18:00');
        $this->attendanceSession($omar, '09:00', '15:15');

        Livewire::test(ListAttendances::class)
            ->assertTableColumnSummarySet(
                'duration',
                'total_inside',
                __('attendance.units.duration', ['hours' => '14', 'minutes' => '15']),
            )
            ->filterTable('user_id', $sara->id)
            ->assertTableColumnSummarySet(
                'duration',
                'total_inside',
                __('attendance.units.duration', ['hours' => '8', 'minutes' => '0']),
            );
    }

    #[Test]
    public function the_list_can_be_grouped_by_day(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $yesterday = $this->today()->subDay();

        $morning = $this->attendanceSession($sara, '08:00', '12:00');
        $afternoon = $this->attendanceSession($sara, '13:00', '17:00');
        $before = $this->attendanceSession($sara, '08:00', '16:00', $yesterday);

        Livewire::test(ListAttendances::class)
            ->set('tableGrouping', 'attendance_date')
            ->assertCanSeeTableRecords([$morning, $afternoon, $before])
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
    public function the_date_filter_keeps_every_session_of_the_day_it_selects(): void
    {
        $sara = $this->makeEmployee();
        $yesterday = $this->today()->subDay();

        $first = $this->attendanceSession($sara, '08:00', '12:00', $yesterday);
        $second = $this->attendanceSession($sara, '13:00', '17:00', $yesterday);
        $todays = $this->attendanceSession($sara, '08:30');

        Livewire::test(ListAttendances::class)
            ->filterTable('date', ['date' => $yesterday->toDateString()])
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$todays]);
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
        $from = $today->subDays(5);
        $until = $today->subDay();

        // Both bounds are inclusive, so the records sitting exactly on
        // them are in, and the ones a single day beyond them are out.
        $onFrom = $this->checkedOut($sara, $from);
        $withinRange = $this->checkedOut($sara, $today->subDays(3));
        $onUntil = $this->checkedOut($sara, $until);
        $dayBeforeFrom = $this->checkedOut($sara, $from->subDay());
        $dayAfterUntil = $this->checkedIn($sara);
        $tooOld = $this->checkedOut($sara, $today->subDays(10));

        Livewire::test(ListAttendances::class)
            ->filterTable('date_range', [
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
            ])
            ->assertCanSeeTableRecords([$onFrom, $withinRange, $onUntil])
            ->assertCanNotSeeTableRecords([$dayBeforeFrom, $dayAfterUntil, $tooOld]);
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

    #[Test]
    public function a_moved_time_is_marked_corrected_by_word_glyph_and_colour_at_once(): void
    {
        $this->freezeRiyadhClock('2026-09-08 18:00');

        $sara = $this->makeEmployee('sara@company.test');
        $session = $this->attendanceSession($sara, '09:15', '17:00');

        $this->approve($this->correctionRequest($sara, $session, checkIn: '08:00'));

        $session->refresh();

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$session])
            // The word, so the mark survives greyscale and a screen reader.
            ->assertTableColumnHasDescription(
                'check_in_at',
                __('attendance.badges.corrected'),
                $session,
                position: 'above',
            )
            // What the device recorded, under the time that replaced it.
            ->assertTableColumnHasDescription(
                'check_in_at',
                __('attendance.badges.corrected_from', ['time' => '09:15']),
                $session,
            )
            // The check-out was not touched, so it carries no mark at all.
            ->assertTableColumnDoesNotHaveDescription(
                'check_out_at',
                __('attendance.badges.corrected'),
                $session,
                position: 'above',
            );

        // The glyph and the colour, the other two of the three signals.
        $column = $this->listColumn('check_in_at', $session);

        $this->assertSame(Heroicon::OutlinedPencilSquare, $column->getIcon($session->check_in_at));
        $this->assertSame('info', $column->getColor($session->check_in_at));
    }

    #[Test]
    public function a_time_the_device_never_recorded_says_it_was_recorded_by_hand(): void
    {
        $this->freezeRiyadhClock('2026-09-08 18:00');

        $sara = $this->makeEmployee('sara@company.test');

        // No session that day at all: the approval manufactures one, and
        // neither of its moments has a reading behind it.
        $session = $this->approve($this->correctionRequest($sara));

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$session])
            ->assertTableColumnHasDescription(
                'check_in_at',
                __('attendance.badges.recorded_manually'),
                $session,
                position: 'above',
            )
            ->assertTableColumnHasDescription(
                'check_out_at',
                __('attendance.badges.recorded_manually'),
                $session,
                position: 'above',
            )
            // No device time to print under it: the word above already
            // says the device recorded nothing.
            ->assertTableColumnDoesNotHaveDescription(
                'check_in_at',
                __('attendance.badges.corrected_from', ['time' => '08:00']),
                $session,
            );
    }

    #[Test]
    public function an_untouched_session_carries_no_mark(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $session = $this->attendanceSession($sara, '08:00', '17:00');

        Livewire::test(ListAttendances::class)
            ->assertTableColumnDoesNotHaveDescription(
                'check_in_at',
                __('attendance.badges.corrected'),
                $session,
                position: 'above',
            )
            ->assertTableColumnDoesNotHaveDescription(
                'check_in_at',
                __('attendance.badges.recorded_manually'),
                $session,
                position: 'above',
            );

        $column = $this->listColumn('check_in_at', $session);

        $this->assertNull($column->getIcon($session->check_in_at));
        $this->assertNull($column->getColor($session->check_in_at));
    }

    #[Test]
    public function the_corrected_filter_narrows_the_list_to_the_amended_records(): void
    {
        $this->freezeRiyadhClock('2026-09-08 18:00');

        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $amended = $this->attendanceSession($sara, '09:15', '17:00');
        $this->approve($this->correctionRequest($sara, $amended, checkIn: '08:00'));

        $untouched = $this->attendanceSession($omar, '08:00', '17:00');

        Livewire::test(ListAttendances::class)
            ->assertCanSeeTableRecords([$amended, $untouched])
            ->filterTable('corrected', true)
            ->assertCanSeeTableRecords([$amended])
            ->assertCanNotSeeTableRecords([$untouched])
            ->filterTable('corrected', false)
            ->assertCanSeeTableRecords([$untouched])
            ->assertCanNotSeeTableRecords([$amended]);
    }

    #[Test]
    public function the_record_modal_shows_what_the_device_recorded_and_who_approved_the_change(): void
    {
        $this->freezeRiyadhClock('2026-09-08 18:00');

        $sara = $this->makeEmployee('sara@company.test');
        $session = $this->attendanceSession($sara, '09:15', '17:00');

        $this->approve($this->correctionRequest($sara, $session, checkIn: '08:00'));

        Livewire::test(ListAttendances::class)
            ->mountTableAction('view', $session->fresh())
            ->assertMountedActionModalSee([
                __('attendance.sections.correction'),
                __('attendance.fields.original_check_in_at'),
                '2026-09-08 09:15',
                __('attendance.fields.corrected_by'),
                $this->admin->name,
                // The distance beside a corrected time is the one figure on
                // this modal that could be read as a lie, so it says which
                // moment it describes.
                __('attendance.helpers.distance_describes_device_reading'),
                __('attendance.admin_actions.open_correction'),
            ]);
    }

    #[Test]
    public function an_untouched_record_says_nothing_about_corrections(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $session = $this->attendanceSession($sara, '08:00', '17:00');

        Livewire::test(ListAttendances::class)
            ->mountTableAction('view', $session)
            ->assertMountedActionModalDontSee(__('attendance.sections.correction'))
            ->assertMountedActionModalDontSee(__('attendance.helpers.distance_describes_device_reading'));
    }

    #[Test]
    public function a_moment_the_device_never_recorded_offers_no_map(): void
    {
        $this->freezeRiyadhClock('2026-09-08 18:00');

        $sara = $this->makeEmployee('sara@company.test');

        $session = $this->approve($this->correctionRequest($sara));

        Livewire::test(ListAttendances::class)
            ->mountTableAction('view', $session)
            // A button that links nowhere is worse than no button.
            ->assertMountedActionModalDontSee(__('attendance.admin_actions.open_map'))
            ->assertMountedActionModalSee(__('attendance.placeholders.no_device_record'))
            // And no sentence about a distance that does not exist: the
            // helper qualifies a measurement, and nothing was measured.
            ->assertMountedActionModalDontSee(__('attendance.helpers.distance_describes_device_reading'));
    }
}
