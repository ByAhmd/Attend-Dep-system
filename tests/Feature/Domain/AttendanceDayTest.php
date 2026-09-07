<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceDaySummary;
use App\Support\Attendance\SessionDuration;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * A day read as the sessions it is made of.
 *
 * Once an employee can leave and come back, "how long were they here" stops
 * being a subtraction of two columns and becomes the sum of the sessions
 * they closed. These tests pin that arithmetic, the scope it is computed
 * over - one employee, one Riyadh calendar day - and the fact that a
 * session still running contributes nothing until it is closed.
 */
final class AttendanceDayTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $employee;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 14:00:00');

        $this->employee = $this->makeEmployee();
        $this->today = app(AttendanceCalendar::class)->today();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_closed_session_knows_how_long_it_lasted(): void
    {
        $session = $this->attendanceSession($this->employee, '08:15', '12:45');

        $this->assertSame(4 * 3600 + 30 * 60, $session->durationInSeconds());
    }

    #[Test]
    public function an_open_session_has_no_duration_yet(): void
    {
        $session = $this->attendanceSession($this->employee, '08:15');

        $this->assertNull($session->durationInSeconds());
    }

    #[Test]
    public function a_days_total_inside_is_the_sum_of_its_closed_sessions(): void
    {
        $this->attendanceSession($this->employee, '08:00', '12:00');
        $this->attendanceSession($this->employee, '13:00', '17:00');
        $this->attendanceSession($this->employee, '18:00');

        $day = AttendanceDaySummary::forEmployee($this->employee, $this->today);

        $this->assertSame(3, $day->sessionCount());
        // Eight hours inside: the session that is still running adds
        // nothing until it is closed.
        $this->assertSame(8 * 3600, $day->secondsInside());
        $this->assertTrue($day->isCheckedIn());
    }

    #[Test]
    public function a_day_with_nothing_recorded_is_empty_rather_than_absent(): void
    {
        $day = AttendanceDaySummary::forEmployee($this->employee, $this->today);

        $this->assertSame(0, $day->sessionCount());
        $this->assertSame(0, $day->secondsInside());
        $this->assertFalse($day->isCheckedIn());
        $this->assertNull($day->openSession());
        $this->assertNull($day->latestSession());
    }

    #[Test]
    public function the_open_session_is_the_one_still_running_and_the_latest_is_the_last_one_started(): void
    {
        $morning = $this->attendanceSession($this->employee, '08:00', '12:00');
        $afternoon = $this->attendanceSession($this->employee, '13:00');

        $day = AttendanceDaySummary::forEmployee($this->employee, $this->today);

        $this->assertSame($afternoon->id, $day->openSession()?->id);
        $this->assertSame($afternoon->id, $day->latestSession()?->id);
        $this->assertSame(
            [$morning->id, $afternoon->id],
            $day->sessions()->map(static fn (Attendance $session): int => $session->id)->all(),
        );
    }

    #[Test]
    public function the_latest_session_is_the_last_one_closed_when_nothing_is_running(): void
    {
        $this->attendanceSession($this->employee, '08:00', '12:00');
        $afternoon = $this->attendanceSession($this->employee, '13:00', '17:00');

        $day = AttendanceDaySummary::forEmployee($this->employee, $this->today);

        $this->assertNull($day->openSession());
        $this->assertFalse($day->isCheckedIn());
        $this->assertSame($afternoon->id, $day->latestSession()?->id);
    }

    #[Test]
    public function a_day_holds_only_that_employee_and_only_that_riyadh_day(): void
    {
        $colleague = $this->makeEmployee();
        $yesterday = $this->today->subDay();

        $mine = $this->attendanceSession($this->employee, '08:00', '12:00');
        $this->attendanceSession($this->employee, '08:00', '17:00', $yesterday);
        $this->attendanceSession($colleague, '09:00', '18:00');

        $day = AttendanceDaySummary::forEmployee($this->employee, $this->today);

        $this->assertSame([$mine->id], $day->sessions()->map(static fn (Attendance $s): int => $s->id)->all());
        $this->assertSame(4 * 3600, $day->secondsInside());

        $before = AttendanceDaySummary::forEmployee($this->employee, $yesterday);

        $this->assertSame(9 * 3600, $before->secondsInside());
    }

    #[Test]
    public function a_session_that_crosses_midnight_belongs_to_the_day_it_started(): void
    {
        // The night shift checks in at 22:00 and out at 01:30; the whole
        // session, and its whole length, stay on the day it opened.
        $day = $this->today;

        Attendance::factory()
            ->for($this->employee)
            ->session($day->setTime(22, 0), $day->addDay()->setTime(1, 30))
            ->create();

        $summary = AttendanceDaySummary::forEmployee($this->employee, $day);

        $this->assertSame(1, $summary->sessionCount());
        $this->assertSame(3 * 3600 + 30 * 60, $summary->secondsInside());
        $this->assertSame(0, AttendanceDaySummary::forEmployee($this->employee, $day->addDay())->sessionCount());
    }

    #[Test]
    public function a_duration_reads_as_hours_and_minutes_in_both_languages(): void
    {
        $this->assertSame(__('attendance.units.duration', ['hours' => '8', 'minutes' => '30']), SessionDuration::format(30_600));
        $this->assertSame(__('attendance.units.duration_minutes', ['minutes' => '45']), SessionDuration::format(2_700));
        $this->assertSame(__('attendance.page.not_recorded'), SessionDuration::format(null));

        App::setLocale('en');

        $this->assertSame('8 h 30 m', SessionDuration::format(30_600));
        $this->assertSame('45 m', SessionDuration::format(2_700));
        // Seconds below a minute are not a unit anybody acts on.
        $this->assertSame('0 m', SessionDuration::format(59));
    }
}
