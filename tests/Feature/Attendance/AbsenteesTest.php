<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Enums\LeaveType;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Attendance\Absentees;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Who is listed as not having checked in: active employees on working
 * days, minus everyone with a session, minus everyone excused in writing.
 *
 * 2026-09-02 is a Wednesday; 2026-09-04 is a Friday, the default weekend.
 */
final class AbsenteesTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 10:00:00');
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
        return app(Absentees::class)->queryForToday()->get()
            ->map(static fn (User $user): int => $user->id)
            ->all();
    }

    #[Test]
    public function an_active_employee_with_no_session_is_listed_and_one_who_checked_in_is_not(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $this->checkedIn($omar);

        $this->assertSame([$sara->id], $this->listedUserIds());
    }

    #[Test]
    public function a_session_already_closed_still_counts_as_having_checked_in(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $this->attendanceSession($sara, '08:00', '09:30');

        $this->assertSame([], $this->listedUserIds());
    }

    #[Test]
    public function approved_leave_excuses_the_day_and_a_pending_request_does_not(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $today = CarbonImmutable::parse('2026-09-02', 'Asia/Riyadh');

        $this->approveLeave($this->leaveRequest($sara, $today, $today->addDays(2)));

        // Omar only asked; nobody has agreed to anything yet.
        $this->leaveRequest($omar, $today, $today);

        $this->assertSame([$omar->id], $this->listedUserIds());
    }

    #[Test]
    public function an_approved_exit_and_return_excuses_nothing(): void
    {
        // Hours away is not a day off: the employee is expected to check
        // in on either side of it.
        $sara = $this->makeEmployee('sara@company.test');

        $today = CarbonImmutable::parse('2026-09-02', 'Asia/Riyadh');

        $request = $this->leaveRequest($sara, $today, $today, LeaveType::Annual);
        $request->forceFill(['is_exit_and_return' => true])->save();
        $this->approveLeave($request);

        $this->assertSame([$sara->id], $this->listedUserIds());
    }

    #[Test]
    public function nobody_is_listed_on_a_weekend_or_a_holiday(): void
    {
        $this->makeEmployee('sara@company.test');

        $this->freezeRiyadhClock('2026-09-04 10:00:00');

        $this->assertSame([], $this->listedUserIds());

        $this->freezeRiyadhClock('2026-09-06 10:00:00');
        $this->makeHoliday(CarbonImmutable::parse('2026-09-06', 'Asia/Riyadh'));

        $this->assertSame([], $this->listedUserIds());
    }

    #[Test]
    public function only_active_employee_accounts_are_expected(): void
    {
        $this->makeEmployee('inactive@company.test', UserStatus::Inactive);
        $this->makeEmployee('pending@company.test', UserStatus::Pending);
        $this->makeAdmin('admin@company.test');

        $sara = $this->makeEmployee('sara@company.test');

        $this->assertSame([$sara->id], $this->listedUserIds());
    }

    #[Test]
    public function a_deleted_account_is_not_expected(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $gone = $this->makeEmployee('gone@company.test');

        $gone->delete();

        $this->assertSame([$sara->id], $this->listedUserIds());
    }
}
