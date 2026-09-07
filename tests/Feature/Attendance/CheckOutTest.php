<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceWorkflow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Check-out through AttendanceWorkflow.
 *
 * A check-out closes the session that is open TODAY - there is at most one -
 * and nothing else: a session already closed this morning stays as it was,
 * and one left open yesterday stays open, because inventing a check-out for
 * a day that has ended is inventing attendance.
 *
 * The clock is frozen in the Riyadh afternoon, after the fixture's 08:02
 * check-in, so a check-out always lands later than the check-in it closes.
 */
final class CheckOutTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private AttendanceWorkflow $workflow;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 17:30:00');
        $this->configureCompanyLocation();

        $this->workflow = app(AttendanceWorkflow::class);
        $this->employee = $this->makeEmployee();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_check_out_inside_the_radius_closes_the_record_with_the_server_time_and_what_the_server_saw(): void
    {
        $open = $this->checkedIn($this->employee);
        $now = $this->freezeRiyadhClock('2026-09-02 17:04:29');
        $reading = $this->readingMetersFromCompany(40.0, accuracyMeters: 8.0);

        $attendance = $this->workflow->checkOut($this->employee, $reading);

        $this->assertSame($open->id, $attendance->id);
        $this->assertDatabaseCount('attendances', 1);

        $stored = $attendance->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertInstanceOf(CarbonImmutable::class, $stored->check_out_at);
        $this->assertSame($now->toDateTimeString(), $stored->check_out_at->toDateTimeString());
        $this->assertSame('Asia/Riyadh', $stored->check_out_at->timezoneName);
        $this->assertEqualsWithDelta($reading->coordinates->latitude, (float) $stored->check_out_latitude, 1e-7);
        $this->assertEqualsWithDelta($reading->coordinates->longitude, (float) $stored->check_out_longitude, 1e-7);
        $this->assertSame('8.00', $stored->check_out_accuracy);
        $this->assertSame('40.00', $stored->check_out_distance_from_company);
        $this->assertFalse($stored->isOpen());
        $this->assertSame(AttendanceStatus::CheckedOut, $stored->status());

        // The check-in half of the row is untouched.
        $this->assertSame($open->check_in_at->toDateTimeString(), $stored->check_in_at->toDateTimeString());
        $this->assertSame($open->check_in_distance_from_company, $stored->check_in_distance_from_company);
    }

    #[Test]
    public function a_reading_exactly_on_the_radius_line_closes_the_record(): void
    {
        $this->checkedIn($this->employee);

        $attendance = $this->workflow->checkOut($this->employee, $this->readingMetersFromCompany(150.0));

        $this->assertSame('150.00', $attendance->check_out_distance_from_company);
        $this->assertFalse($attendance->isOpen());
    }

    #[Test]
    public function a_check_out_outside_the_radius_is_rejected_and_the_record_stays_open(): void
    {
        $open = $this->checkedIn($this->employee);

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingMetersFromCompany(150.01)),
        );

        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $rejection->reason);
        $this->assertStringContainsString('150', $rejection->getMessage());

        $stored = $open->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertTrue($stored->isOpen());
        $this->assertNull($stored->check_out_latitude);
        $this->assertNull($stored->check_out_distance_from_company);
        $this->assertSame(AttendanceStatus::CheckedIn, $stored->status());
    }

    #[Test]
    public function a_rejected_check_out_is_audited_as_a_check_out(): void
    {
        $this->checkedIn($this->employee);
        $reading = $this->readingMetersFromCompany(320.0, accuracyMeters: 11.0);

        $this->expectRejection(fn (): Attendance => $this->workflow->checkOut($this->employee, $reading));

        $this->assertDatabaseCount('attendance_rejections', 1);

        $audit = AttendanceRejection::query()->firstOrFail();

        $this->assertSame($this->employee->id, $audit->user_id);
        $this->assertSame(AttendanceAction::CheckOut, $audit->action);
        $this->assertEqualsWithDelta($reading->coordinates->latitude, (float) $audit->latitude, 1e-7);
        $this->assertSame('11.00', $audit->accuracy);
        $this->assertSame('320.00', $audit->distance_from_company);
        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $audit->reason);
    }

    #[Test]
    public function a_bad_fix_cannot_close_the_record(): void
    {
        $open = $this->checkedIn($this->employee);

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany(accuracyMeters: 100.01)),
        );

        $this->assertSame(AttendanceRejectionReason::InsufficientAccuracy, $rejection->reason);
        $this->assertTrue($open->fresh()?->isOpen());
        $this->assertDatabaseCount('attendance_rejections', 1);
    }

    #[Test]
    public function a_check_out_without_a_check_in_is_rejected(): void
    {
        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::NotCheckedIn, $rejection->reason);
        $this->assertNull($rejection->verification);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function yesterdays_open_record_cannot_be_closed_today(): void
    {
        // A missing check-out stays missing: closing it now would record an
        // attendance day that did not happen.
        $yesterday = app(AttendanceCalendar::class)->today()->subDay();
        $forgotten = $this->checkedIn($this->employee, $yesterday);

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::NotCheckedIn, $rejection->reason);

        $stored = $forgotten->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertTrue($stored->isOpen());
        $this->assertSame($yesterday->toDateString(), $stored->attendance_date->toDateString());
        $this->assertSame(AttendanceStatus::MissingCheckOut, $stored->status());
    }

    #[Test]
    public function the_check_out_closes_the_open_session_and_leaves_the_earlier_ones_closed(): void
    {
        $morning = $this->attendanceSession($this->employee, '08:00', '12:30');
        $afternoon = $this->attendanceSession($this->employee, '13:05');
        $now = $this->freezeRiyadhClock('2026-09-02 17:20:00');

        $closed = $this->workflow->checkOut($this->employee, $this->readingAtCompany());

        $this->assertSame($afternoon->id, $closed->id);
        $this->assertSame($now->toDateTimeString(), $closed->check_out_at?->toDateTimeString());
        $this->assertSame('2026-09-02 12:30:00', $morning->fresh()?->check_out_at?->toDateTimeString());
        $this->assertDatabaseCount('attendances', 2);
    }

    #[Test]
    public function a_check_out_with_no_open_session_today_is_refused(): void
    {
        // Two completed sessions and nothing running: there is nothing left
        // to close until the employee checks in again.
        $this->attendanceSession($this->employee, '08:00', '12:30');
        $this->attendanceSession($this->employee, '13:05', '17:00');

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::AlreadyCheckedOut, $rejection->reason);
        $this->assertSame(0, Attendance::query()->open()->count());
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function a_second_check_out_is_rejected_and_the_first_stands(): void
    {
        $closed = $this->checkedOut($this->employee);
        $firstCheckOut = $closed->check_out_at;

        $this->assertInstanceOf(CarbonImmutable::class, $firstCheckOut);

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::AlreadyCheckedOut, $rejection->reason);
        $this->assertSame($firstCheckOut->toDateTimeString(), $closed->fresh()?->check_out_at?->toDateTimeString());
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function an_account_deactivated_while_checked_in_cannot_check_out(): void
    {
        $open = $this->checkedIn($this->employee);
        $this->employee->forceFill(['status' => UserStatus::Inactive])->save();

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::InactiveAccount, $rejection->reason);
        $this->assertTrue($open->fresh()?->isOpen());
    }

    #[Test]
    public function a_company_location_removed_after_check_in_blocks_the_check_out(): void
    {
        $open = $this->checkedIn($this->employee);
        AttendanceSetting::current()->forceFill(['latitude' => null, 'longitude' => null])->save();

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::LocationNotConfigured, $rejection->reason);
        $this->assertTrue($open->fresh()?->isOpen());
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function a_wider_radius_applies_to_check_out_as_well(): void
    {
        $this->configureCompanyLocation(radiusMeters: 300);
        $this->checkedIn($this->employee);

        $attendance = $this->workflow->checkOut($this->employee, $this->readingMetersFromCompany(250.0));

        $this->assertSame('250.00', $attendance->check_out_distance_from_company);
    }

    /**
     * @param  callable(): Attendance  $operation
     */
    private function expectRejection(callable $operation): AttendanceRejectedException
    {
        try {
            $operation();
        } catch (AttendanceRejectedException $rejection) {
            return $rejection;
        }

        $this->fail('The attendance workflow accepted an operation it should have rejected.');
    }
}
