<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\LateArrivals;
use App\Support\Attendance\WorkingHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Who counts as late today: the verdict belongs to the employee-day and is
 * made by its first check-in alone, against the working day currently in
 * the settings.
 */
final class LateArrivalsTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private LateArrivals $lateArrivals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 11:00:00');

        $this->lateArrivals = app(LateArrivals::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<int>
     */
    private function listedUserIds(): array
    {
        return $this->lateArrivals->queryForToday()->get()
            ->map(static fn (Attendance $session): int => $session->user_id)
            ->all();
    }

    #[Test]
    public function an_arrival_after_the_grace_is_listed_and_an_on_time_one_is_not(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $this->attendanceSession($sara, '09:45');
        $this->attendanceSession($omar, '09:10');

        $this->assertSame([$sara->id], $this->listedUserIds());
    }

    #[Test]
    public function the_grace_boundary_is_strict(): void
    {
        $onTheLine = $this->makeEmployee('line@company.test');
        $justOver = $this->makeEmployee('over@company.test');

        $this->attendanceSession($onTheLine, '09:30:00');
        $this->attendanceSession($justOver, '09:30:01');

        $this->assertSame([$justOver->id], $this->listedUserIds());
    }

    #[Test]
    public function a_return_from_lunch_is_not_a_late_arrival(): void
    {
        // Sara arrived on time and came back at 13:00; her day was decided
        // by the 09:05 check-in and nothing after it.
        $sara = $this->makeEmployee('sara@company.test');

        $this->attendanceSession($sara, '09:05', '12:00');
        $this->attendanceSession($sara, '13:00');

        $this->assertSame([], $this->listedUserIds());
    }

    #[Test]
    public function a_late_day_is_listed_once_however_many_sessions_it_holds(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $first = $this->attendanceSession($sara, '09:50', '12:00');
        $this->attendanceSession($sara, '13:00');

        $listed = $this->lateArrivals->queryForToday()->get();

        $this->assertCount(1, $listed);
        $this->assertSame($first->id, $listed->first()?->id);
    }

    #[Test]
    public function yesterdays_lateness_is_not_todays(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $this->attendanceSession($sara, '10:30', '17:10', app(AttendanceCalendar::class)->today()->subDay());

        $this->assertSame([], $this->listedUserIds());
    }

    #[Test]
    public function the_verdict_follows_the_working_day_in_the_settings(): void
    {
        // An 08:20 arrival is late once the owner moves the day to start
        // at 07:00 with a ten-minute grace...
        $this->configureWorkingHours(startsAt: '07:00', graceMinutes: 10);

        $sara = $this->makeEmployee('sara@company.test');
        $this->attendanceSession($sara, '08:20');

        $this->assertSame([$sara->id], $this->listedUserIds());

        // ...and on time again when the day starts at 08:30.
        $this->configureWorkingHours(startsAt: '08:30', graceMinutes: 10);

        $this->assertSame([], $this->listedUserIds());
    }

    #[Test]
    public function the_list_reads_in_arrival_order_and_lateness_is_measured_from_the_start(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $this->attendanceSession($omar, '10:15');
        $this->attendanceSession($sara, '09:45');

        $this->assertSame([$sara->id, $omar->id], $this->listedUserIds());

        // The figure the widget prints beside Sara: 45 minutes measured
        // from the 09:00 start, not 15 from the end of the grace.
        $workingHours = new WorkingHours('09:00', '17:00', 30);
        $session = $this->lateArrivals->queryForToday()->first();

        $this->assertInstanceOf(Attendance::class, $session);
        $this->assertSame(45 * 60, $workingHours->latenessSeconds($session->check_in_at));
    }
}
