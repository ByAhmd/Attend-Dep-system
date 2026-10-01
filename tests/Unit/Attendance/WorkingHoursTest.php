<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Support\Attendance\WorkingHours;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The working-day arithmetic, on the boundaries the sentences are written
 * against: late is STRICTLY after start-plus-grace, early is STRICTLY
 * before the end, and lateness is measured from the start of the day, not
 * from the end of the grace.
 */
final class WorkingHoursTest extends TestCase
{
    private const string TIMEZONE = 'Asia/Riyadh';

    private function nineToFive(int $graceMinutes = 30): WorkingHours
    {
        return new WorkingHours('09:00', '17:00', $graceMinutes);
    }

    private function at(string $riyadhDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($riyadhDateTime, self::TIMEZONE);
    }

    #[Test]
    public function the_thresholds_are_pinned_to_the_day_they_are_asked_about(): void
    {
        $hours = $this->nineToFive();
        $day = $this->at('2026-09-02 00:00:00');

        $this->assertSame('2026-09-02 09:00:00', $hours->startOn($day)->toDateTimeString());
        $this->assertSame('2026-09-02 09:30:00', $hours->lateThresholdOn($day)->toDateTimeString());
        $this->assertSame('2026-09-02 17:00:00', $hours->endOn($day)->toDateTimeString());

        // Asked with a moment mid-day, the answers stay on that day.
        $this->assertSame(
            '2026-09-05 17:00:00',
            $hours->endOn($this->at('2026-09-05 12:34:56'))->toDateTimeString(),
        );
    }

    #[Test]
    public function the_seconds_stored_by_a_time_column_are_understood(): void
    {
        $hours = new WorkingHours('09:00:00', '17:00:00', 30);

        $this->assertSame(
            '2026-09-02 09:30:00',
            $hours->lateThresholdOn($this->at('2026-09-02 00:00:00'))->toDateTimeString(),
        );
    }

    #[Test]
    public function an_arrival_is_late_strictly_after_the_grace_runs_out(): void
    {
        $hours = $this->nineToFive();

        $this->assertFalse($hours->isLateArrival($this->at('2026-09-02 09:30:00')));
        $this->assertTrue($hours->isLateArrival($this->at('2026-09-02 09:30:01')));
        $this->assertFalse($hours->isLateArrival($this->at('2026-09-02 08:15:00')));
    }

    #[Test]
    public function a_zero_grace_makes_any_arrival_after_the_start_late(): void
    {
        $hours = $this->nineToFive(graceMinutes: 0);

        $this->assertFalse($hours->isLateArrival($this->at('2026-09-02 09:00:00')));
        $this->assertTrue($hours->isLateArrival($this->at('2026-09-02 09:00:01')));
    }

    #[Test]
    public function lateness_is_measured_from_the_start_of_the_day_not_from_the_grace(): void
    {
        $hours = $this->nineToFive();

        // 09:45 against a 09:00 start missed 45 minutes, not 15.
        $this->assertSame(45 * 60, $hours->latenessSeconds($this->at('2026-09-02 09:45:00')));
    }

    #[Test]
    public function an_early_bird_missed_nothing(): void
    {
        $this->assertSame(0, $this->nineToFive()->latenessSeconds($this->at('2026-09-02 08:40:00')));
    }

    #[Test]
    public function a_check_out_is_early_strictly_before_the_end(): void
    {
        $hours = $this->nineToFive();

        $this->assertTrue($hours->isEarlyCheckOut($this->at('2026-09-02 16:59:59')));
        $this->assertFalse($hours->isEarlyCheckOut($this->at('2026-09-02 17:00:00')));
        $this->assertFalse($hours->isEarlyCheckOut($this->at('2026-09-02 18:20:00')));
    }

    #[Test]
    public function a_day_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkingHours('17:00', '09:00', 30);
    }

    #[Test]
    public function a_negative_grace_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkingHours('09:00', '17:00', -1);
    }
}
