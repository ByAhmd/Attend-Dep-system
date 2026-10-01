<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Enums\LeaveType;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveBalance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The annual balance: the company figure or the account's own, minus the
 * approved annual leave of the current year, counted in working days.
 */
final class LeaveBalanceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private LeaveBalance $balance;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 10:00:00');

        $this->balance = app(LeaveBalance::class);
        $this->employee = $this->makeEmployee();
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

    private function approvedAnnual(CarbonImmutable $from, CarbonImmutable $until): LeaveRequest
    {
        return $this->approveLeave($this->leaveRequest($this->employee, $from, $until, LeaveType::Annual));
    }

    #[Test]
    public function the_entitlement_is_the_company_figure_unless_the_account_carries_its_own(): void
    {
        $this->assertSame(21, $this->balance->entitlementFor($this->employee));

        AttendanceSetting::current()->forceFill(['annual_leave_days' => 25])->save();

        $this->assertSame(25, $this->balance->entitlementFor($this->employee));

        $this->employee->forceFill(['annual_leave_override' => 30])->save();

        $this->assertSame(30, $this->balance->entitlementFor($this->employee));
    }

    #[Test]
    public function used_days_are_working_days_of_approved_annual_leave_only(): void
    {
        // Sun 6 .. Thu 10 September: five working days.
        $this->approvedAnnual($this->day('2026-09-06'), $this->day('2026-09-10'));

        // Sick leave is its own agreement and never draws on the annual
        // allowance, however many working days it covers.
        $this->approveLeave($this->leaveRequest($this->employee, $this->day('2026-08-20'), $this->day('2026-08-21'), LeaveType::Sick));

        // A pending annual request is a question, not a deduction.
        $this->leaveRequest($this->employee, $this->day('2026-10-04'), $this->day('2026-10-08'), LeaveType::Annual);

        $this->assertSame(5, $this->balance->usedThisYear($this->employee));
        $this->assertSame(16, $this->balance->remainingFor($this->employee));
    }

    #[Test]
    public function a_weekend_inside_approved_leave_costs_nothing(): void
    {
        // Wed 2 .. Sun 6: five calendar days across the weekend, three
        // working ones.
        $this->approvedAnnual($this->day('2026-09-02'), $this->day('2026-09-06'));

        $this->assertSame(3, $this->balance->usedThisYear($this->employee));
    }

    #[Test]
    public function a_holiday_inside_approved_leave_costs_nothing(): void
    {
        $this->makeHoliday($this->day('2026-09-23'));

        // Sun 20 .. Thu 24, with the national day inside: four working days.
        $this->approvedAnnual($this->day('2026-09-20'), $this->day('2026-09-24'));

        $this->assertSame(4, $this->balance->usedThisYear($this->employee));
    }

    #[Test]
    public function only_the_current_years_days_are_counted(): void
    {
        // Across new year: Dec 29-31 2026 (Tue-Thu, three working days)
        // belong to this year; the January days do not.
        $this->approvedAnnual($this->day('2026-12-29'), $this->day('2027-01-04'));

        $this->assertSame(3, $this->balance->usedThisYear($this->employee));
    }

    #[Test]
    public function an_approved_exit_and_return_draws_nothing(): void
    {
        $request = $this->approvedAnnual($this->day('2026-09-02'), $this->day('2026-09-02'));
        $request->forceFill(['is_exit_and_return' => true])->save();

        $this->assertSame(0, $this->balance->usedThisYear($this->employee));
        $this->assertSame(0, $this->balance->costOf($request->refresh()));
    }

    #[Test]
    public function the_balance_may_go_negative_and_says_so_honestly(): void
    {
        $this->employee->forceFill(['annual_leave_override' => 2])->save();

        // Sun 6 .. Thu 10: five working days against an allowance of two.
        $this->approvedAnnual($this->day('2026-09-06'), $this->day('2026-09-10'));

        $this->assertSame(-3, $this->balance->remainingFor($this->employee));
    }

    #[Test]
    public function the_cost_of_a_request_is_its_working_days(): void
    {
        $request = $this->leaveRequest($this->employee, $this->day('2026-09-02'), $this->day('2026-09-06'), LeaveType::Annual);

        $this->assertSame(3, $this->balance->costOf($request));
    }
}
