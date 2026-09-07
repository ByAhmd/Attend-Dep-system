<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Services\Attendance\AttendanceCalendar;
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
}
