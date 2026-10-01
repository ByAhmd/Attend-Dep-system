<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Data\Attendance\MonthlyEmployeeSummary;
use App\Enums\EarlyCheckOutReason;
use App\Filament\Pages\MonthlyReport;
use App\Services\Attendance\MonthlyAttendanceSummary;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The month folded per employee, and the page that prints it.
 *
 * The frozen day is Thursday 2026-09-10, so the elapsed working days of
 * September are the 1st to the 10th minus Friday the 4th and Saturday the
 * 5th: eight days. Every figure asserted below is arithmetic over that
 * ruler.
 */
final class MonthlyReportTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 18:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function september(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-01', 'Asia/Riyadh');
    }

    private function rowFor(string $name): MonthlyEmployeeSummary
    {
        foreach (app(MonthlyAttendanceSummary::class)->rows($this->september()) as $row) {
            if ($row->employeeName === $name) {
                return $row;
            }
        }

        $this->fail("No report row for {$name}.");
    }

    #[Test]
    public function the_elapsed_working_days_stop_at_today(): void
    {
        $this->assertCount(8, app(MonthlyAttendanceSummary::class)->elapsedWorkingDays($this->september()));
    }

    #[Test]
    public function a_future_month_has_no_elapsed_days_and_all_zero_rows(): void
    {
        $this->makeEmployee('sara@company.test');

        $october = CarbonImmutable::parse('2026-10-01', 'Asia/Riyadh');
        $service = app(MonthlyAttendanceSummary::class);

        $this->assertSame([], $service->elapsedWorkingDays($october));

        $row = $service->rows($october)[0];

        $this->assertSame(0, $row->daysAttended);
        $this->assertSame(0, $row->daysUnrecorded);
    }

    #[Test]
    public function the_row_restates_the_stored_month(): void
    {
        $employee = $this->makeEmployee();
        $employee->forceFill(['name' => 'Sara'])->save();

        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'Asia/Riyadh');

        // Two ordinary days, one of them split by lunch...
        $this->attendanceSession($employee, '09:00', '12:00', $day('2026-09-01'));
        $this->attendanceSession($employee, '13:00', '17:00', $day('2026-09-01'));
        $this->attendanceSession($employee, '08:55', '17:10', $day('2026-09-02'));

        // ...one late morning (45 minutes from the 09:00 start)...
        $this->attendanceSession($employee, '09:45', '17:00', $day('2026-09-03'));

        // ...and one early departure, its reason on the row.
        $this->attendanceSession($employee, '09:05', '15:00', $day('2026-09-06'))
            ->forceFill(['early_check_out_reason' => EarlyCheckOutReason::Sick])->save();

        // Two working days of approved leave (Mon 7 - Tue 8).
        $this->approveLeave($this->leaveRequest($employee, $day('2026-09-07'), $day('2026-09-08')));

        $row = $this->rowFor('Sara');

        $this->assertSame(4, $row->daysAttended);
        // 3h + 4h + 8h15 + 7h15 + 5h55 of sessions, in seconds.
        $this->assertSame((3 * 60 + 4 * 60 + 495 + 435 + 355) * 60, $row->secondsInside);
        $this->assertSame(1, $row->lateDays);
        $this->assertSame(45 * 60, $row->latenessSeconds);
        $this->assertSame(1, $row->earlyCheckOuts);
        $this->assertSame(2, $row->leaveDays);
        // Eight elapsed working days, four attended, two on leave: the
        // 9th and the 10th carry nothing.
        $this->assertSame(2, $row->daysUnrecorded);
    }

    #[Test]
    public function a_session_on_a_leave_day_counts_as_attended_not_leave(): void
    {
        $employee = $this->makeEmployee();
        $employee->forceFill(['name' => 'Omar'])->save();

        $day = CarbonImmutable::parse('2026-09-02', 'Asia/Riyadh');

        $this->approveLeave($this->leaveRequest($employee, $day, $day));
        $this->attendanceSession($employee, '10:00', '14:00', $day);

        $row = $this->rowFor('Omar');

        $this->assertSame(1, $row->daysAttended);
        $this->assertSame(0, $row->leaveDays);
    }

    #[Test]
    public function the_page_renders_with_the_figures_and_exports_the_csv(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());

        $employee = $this->makeEmployee();
        $employee->forceFill(['name' => 'Sara Report'])->save();
        $this->attendanceSession($employee, '09:00', '17:00', CarbonImmutable::parse('2026-09-02', 'Asia/Riyadh'));

        $page = Livewire::test(MonthlyReport::class);

        $page
            ->assertSet('month', '2026-09')
            ->assertOk()
            ->assertSee(__('reports.fields.days_attended'))
            ->assertSee('Sara Report');

        $page
            ->callAction('export')
            ->assertFileDownloaded('attendance-2026-09.csv');
    }

    #[Test]
    public function an_unparseable_month_falls_back_to_the_current_one(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());

        Livewire::test(MonthlyReport::class)
            ->set('month', 'not-a-month')
            ->assertOk()
            ->assertSee(__('reports.working_days_elapsed'));
    }

    #[Test]
    public function an_employee_is_refused_the_report_over_http(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeEmployee());

        $this->get('/admin/monthly-report')->assertForbidden();
    }
}
