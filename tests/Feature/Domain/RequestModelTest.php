<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What a loaded request answers, as opposed to what its table refuses.
 *
 * The questions here are all the same question asked in different ways -
 * "does this leave cover that moment" - and every caller that will ever ask
 * it hands over a timestamp, not a date: a check-in stamped in Riyadh, the
 * calendar's now(), the day an administrator is looking at. starts_on and
 * ends_on are dates, so the two sides are not the same kind of thing, and
 * getting that wrong is invisible until the last morning of somebody's
 * leave.
 */
final class RequestModelTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function leave_covers_every_hour_of_its_first_and_last_day(): void
    {
        $leave = $this->leaveRequest(
            $this->makeEmployee(),
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-18'),
        );

        foreach (['00:00:00', '08:00:00', '23:59:59'] as $time) {
            $this->assertTrue(
                $leave->coversDate(CarbonImmutable::parse('2026-09-14 '.$time, 'Asia/Riyadh')),
                "the first day at {$time} is outside its own leave",
            );

            $this->assertTrue(
                $leave->coversDate(CarbonImmutable::parse('2026-09-18 '.$time, 'Asia/Riyadh')),
                "the last day at {$time} is outside its own leave",
            );
        }
    }

    #[Test]
    public function leave_covers_neither_the_evening_before_nor_the_morning_after(): void
    {
        $leave = $this->leaveRequest(
            $this->makeEmployee(),
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-18'),
        );

        $this->assertFalse($leave->coversDate(CarbonImmutable::parse('2026-09-13 23:59:59', 'Asia/Riyadh')));
        $this->assertFalse($leave->coversDate(CarbonImmutable::parse('2026-09-19 00:00:00', 'Asia/Riyadh')));
    }

    #[Test]
    public function one_loaded_row_and_the_whole_table_answer_the_same_question_the_same_way(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();

        $leave = $this->leaveRequest($employee, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-18'));
        $leave->forceFill([
            'status' => 'approved',
            'decided_by_id' => $admin->id,
            'decided_at' => Carbon::now(),
        ])->save();

        $leave = $leave->fresh();

        $this->assertInstanceOf(LeaveRequest::class, $leave);

        // A disagreement here means one screen calls somebody absent and
        // another calls them present, on the same day, from the same row.
        foreach ([
            '2026-09-13 08:00:00',
            '2026-09-14 00:00:00',
            '2026-09-14 09:30:00',
            '2026-09-16 13:00:00',
            '2026-09-18 08:00:00',
            '2026-09-18 23:59:59',
            '2026-09-19 08:00:00',
        ] as $moment) {
            $at = CarbonImmutable::parse($moment, 'Asia/Riyadh');

            $this->assertSame(
                LeaveRequest::query()->whereKey($leave->id)->approvedOn($at)->exists(),
                $leave->coversDate($at),
                "the row and the table disagree about {$moment}",
            );
        }
    }

    #[Test]
    public function a_single_day_of_leave_covers_that_day_and_counts_as_one(): void
    {
        $leave = $this->leaveRequest(
            $this->makeEmployee(),
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-14'),
        );

        $this->assertSame(1, $leave->dayCount());
        $this->assertTrue($leave->coversDate(CarbonImmutable::parse('2026-09-14 17:45:00', 'Asia/Riyadh')));
        $this->assertFalse($leave->coversDate(CarbonImmutable::parse('2026-09-15 00:00:00', 'Asia/Riyadh')));
    }

    #[Test]
    public function the_day_count_reads_both_ends_of_the_range_inclusively(): void
    {
        $leave = $this->leaveRequest(
            $this->makeEmployee(),
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-18'),
        );

        $this->assertSame(5, $leave->dayCount());
        $this->assertSame('5 أيام', trans_choice('leave.units.days', $leave->dayCount()));
    }
}
