<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Holiday;
use App\Services\Attendance\WorkingCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Which days anybody is expected at all: the configured weekend, the
 * holiday table, and the counting both feed.
 *
 * 2026-09-02 is a Wednesday, which makes 2026-09-04 a Friday and
 * 2026-09-05 a Saturday - the default weekend - and the first week of
 * September a convenient ruler.
 */
final class WorkingCalendarTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private WorkingCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 10:00:00');

        $this->calendar = app(WorkingCalendar::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Riyadh');
    }

    #[Test]
    public function friday_and_saturday_are_the_weekend_by_default(): void
    {
        $this->assertTrue($this->calendar->isWorkingDay($this->day('2026-09-02')));
        $this->assertFalse($this->calendar->isWorkingDay($this->day('2026-09-04')));
        $this->assertFalse($this->calendar->isWorkingDay($this->day('2026-09-05')));
        $this->assertTrue($this->calendar->isWorkingDay($this->day('2026-09-06')));
    }

    #[Test]
    public function the_weekend_follows_the_settings(): void
    {
        $this->configureWeekend(['sunday']);

        $this->assertTrue($this->calendar->isWorkingDay($this->day('2026-09-04')));
        $this->assertFalse($this->calendar->isWorkingDay($this->day('2026-09-06')));
    }

    #[Test]
    public function a_holiday_covers_every_day_of_its_range_and_nothing_outside_it(): void
    {
        $holiday = $this->makeHoliday($this->day('2026-09-21'), $this->day('2026-09-23'));

        $this->assertFalse($this->calendar->isWorkingDay($this->day('2026-09-21')));
        $this->assertFalse($this->calendar->isWorkingDay($this->day('2026-09-23')));
        $this->assertTrue($this->calendar->isWorkingDay($this->day('2026-09-24')));

        $covering = $this->calendar->holidayCovering($this->day('2026-09-22'));

        $this->assertInstanceOf(Holiday::class, $covering);
        $this->assertSame($holiday->id, $covering->id);
        $this->assertNull($this->calendar->holidayCovering($this->day('2026-09-24')));
    }

    #[Test]
    public function working_days_are_counted_without_the_weekend_and_the_holidays(): void
    {
        // Sep 1 (Tue) .. Sep 10 (Thu): ten days, minus Fri 4 and Sat 5.
        $this->assertSame(8, $this->calendar->workingDaysBetween($this->day('2026-09-01'), $this->day('2026-09-10')));

        // A holiday on Sun 6 - Mon 7 removes two more; one of its days
        // falling on the weekend would remove nothing twice.
        $this->makeHoliday($this->day('2026-09-05'), $this->day('2026-09-07'));

        $this->assertSame(6, $this->calendar->workingDaysBetween($this->day('2026-09-01'), $this->day('2026-09-10')));
    }

    #[Test]
    public function an_inverted_range_holds_no_days(): void
    {
        $this->assertSame(0, $this->calendar->workingDaysBetween($this->day('2026-09-10'), $this->day('2026-09-01')));
    }

    #[Test]
    public function a_single_working_day_counts_itself(): void
    {
        $this->assertSame(1, $this->calendar->workingDaysBetween($this->day('2026-09-02'), $this->day('2026-09-02')));
        $this->assertSame(0, $this->calendar->workingDaysBetween($this->day('2026-09-04'), $this->day('2026-09-04')));
    }
}
