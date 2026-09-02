<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The status of a record is derived from its check-out and its date, never
 * stored, so it follows the server clock without anything updating rows.
 */
final class AttendanceStatusTest extends TestCase
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
    public function an_open_record_for_today_is_a_live_check_in(): void
    {
        $attendance = $this->checkedIn($this->employee);

        $this->assertTrue($attendance->isOpen());
        $this->assertSame(AttendanceStatus::CheckedIn, $attendance->status());
    }

    #[Test]
    public function an_open_record_from_an_earlier_day_is_a_missing_check_out(): void
    {
        $yesterday = $this->checkedIn($this->employee, $this->today->subDay());
        $lastWeek = $this->checkedIn($this->employee, $this->today->subDays(7));

        $this->assertSame(AttendanceStatus::MissingCheckOut, $yesterday->status());
        $this->assertSame(AttendanceStatus::MissingCheckOut, $lastWeek->status());
    }

    #[Test]
    public function a_closed_record_is_checked_out_whatever_its_day(): void
    {
        $today = $this->checkedOut($this->employee);
        $yesterday = $this->checkedOut($this->employee, $this->today->subDay());

        $this->assertFalse($today->isOpen());
        $this->assertSame(AttendanceStatus::CheckedOut, $today->status());
        $this->assertSame(AttendanceStatus::CheckedOut, $yesterday->status());
    }

    #[Test]
    public function a_live_check_in_becomes_a_missing_check_out_when_the_day_ends(): void
    {
        $attendance = $this->checkedIn($this->employee);

        $this->assertSame(AttendanceStatus::CheckedIn, $attendance->status());

        $this->freezeRiyadhClock('2026-09-03 00:00:01');

        $this->assertSame(AttendanceStatus::MissingCheckOut, $attendance->status());
    }

    #[Test]
    public function for_date_selects_one_attendance_day(): void
    {
        $colleague = $this->makeEmployee();

        $todayMine = $this->checkedIn($this->employee);
        $todayTheirs = $this->checkedOut($colleague);
        $this->checkedOut($this->employee, $this->today->subDay());

        $ids = Attendance::query()->forDate($this->today)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$todayMine->id, $todayTheirs->id], $ids);
        $this->assertSame(1, Attendance::query()->forDate($this->today->subDay())->count());
        $this->assertSame(0, Attendance::query()->forDate($this->today->addDay())->count());
    }

    #[Test]
    public function open_selects_records_without_a_check_out(): void
    {
        $liveToday = $this->checkedIn($this->employee);
        $forgottenYesterday = $this->checkedIn($this->employee, $this->today->subDay());
        $this->checkedOut($this->employee, $this->today->subDays(2));

        $ids = Attendance::query()->open()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$liveToday->id, $forgottenYesterday->id], $ids);
        $this->assertSame([$liveToday->id], Attendance::query()->forDate($this->today)->open()->pluck('id')->all());
    }
}
