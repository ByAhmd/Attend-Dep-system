<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The history table under the employee screen.
 *
 * One row is one session, so a day the employee left and returned appears
 * as the sessions it was. Its one promise is scope: an employee reads their
 * own sessions and nobody else's. The ordering and empty state are checked
 * because they are what the employee sees; the scope is checked because it
 * is what they must not.
 */
final class AttendanceHistoryWidgetTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
    }

    #[Test]
    public function it_lists_only_the_signed_in_employees_records_newest_first(): void
    {
        $employee = $this->makeEmployee();
        $other = $this->makeEmployee();

        $today = app(AttendanceCalendar::class)->today();

        $yesterday = $this->checkedOut($employee, $today->subDay());
        $thisMorning = $this->checkedIn($employee);
        $someoneElses = $this->checkedIn($other);

        $this->actingAs($employee);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertCanSeeTableRecords([$thisMorning, $yesterday], inOrder: true)
            ->assertCanNotSeeTableRecords([$someoneElses])
            ->assertSee(__('attendance.history.heading'))
            ->assertOk();
    }

    #[Test]
    public function another_employee_never_sees_records_that_are_not_theirs(): void
    {
        $employee = $this->makeEmployee();
        $other = $this->makeEmployee();

        $mine = $this->checkedIn($employee);

        $this->actingAs($other);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertCanNotSeeTableRecords([$mine])
            ->assertSee(__('attendance.history.empty_heading'))
            ->assertSee(__('attendance.history.empty_description'))
            ->assertOk();
    }

    #[Test]
    public function every_session_of_a_day_is_listed_with_the_latest_first(): void
    {
        $employee = $this->makeEmployee();

        // A day with a lunch break in it: three rows, not one, and the last
        // one started is the one the employee sees at the top.
        $morning = $this->attendanceSession($employee, '08:00', '12:00');
        $afternoon = $this->attendanceSession($employee, '13:00', '17:00');
        $evening = $this->attendanceSession($employee, '18:00');

        $this->actingAs($employee);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertCanSeeTableRecords([$evening, $afternoon, $morning], inOrder: true)
            ->assertSee(__('attendance.fields.duration'))
            ->assertSee(__('attendance.units.duration', ['hours' => '4', 'minutes' => '0']))
            ->assertOk();
    }

    #[Test]
    public function an_open_record_is_labelled_checked_in_and_a_closed_one_checked_out(): void
    {
        $employee = $this->makeEmployee();
        $today = app(AttendanceCalendar::class)->today();

        $this->checkedIn($employee);
        $this->checkedOut($employee, $today->subDay());

        $this->actingAs($employee);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertSee(__('enums.attendance_status.checked_in'))
            ->assertSee(__('enums.attendance_status.checked_out'))
            ->assertSee(__('attendance.placeholders.no_check_out'));
    }

    /**
     * A moment the device recorded and an administrator then moved reads as
     * corrected here, with the device's own time beneath it - the same two
     * sentences the administrator's list prints, on the screen belonging to
     * the person with the most reason to know the difference.
     */
    #[Test]
    public function a_corrected_time_says_so_and_names_what_the_device_recorded(): void
    {
        $this->freezeRiyadhClock('2026-09-15 18:00:00');

        $employee = $this->makeEmployee();
        $day = CarbonImmutable::parse('2026-09-14');
        $session = $this->attendanceSession($employee, '08:00', '12:30', $day);
        $request = $this->correctionRequest($employee, $session, null, '17:00', $day);

        $session->forceFill([
            'original_check_out_at' => $session->check_out_at,
            'check_out_at' => $day->setTimeFromTimeString('17:00'),
            'check_out_correction_id' => $request->id,
        ])->save();

        $this->actingAs($employee);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertOk()
            ->assertSee(__('attendance.badges.corrected_from', ['time' => '12:30']))
            ->assertDontSee(__('attendance.badges.recorded_manually'));
    }

    /**
     * A moment no device ever recorded is a different claim from a moment
     * that was moved, and it gets a different word.
     */
    #[Test]
    public function a_moment_the_device_never_recorded_reads_as_recorded_by_hand(): void
    {
        $this->freezeRiyadhClock('2026-09-15 18:00:00');

        $employee = $this->makeEmployee();
        $day = CarbonImmutable::parse('2026-09-14');
        $session = $this->attendanceSession($employee, '08:00', null, $day);
        $request = $this->correctionRequest($employee, $session, null, '17:00', $day);

        $session->forceFill([
            'check_out_at' => $day->setTimeFromTimeString('17:00'),
            'check_out_correction_id' => $request->id,
        ])->save();

        $this->actingAs($employee);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertOk()
            ->assertSee(__('attendance.badges.recorded_manually'));
    }
}
