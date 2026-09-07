<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Data\Attendance\LocationVerification;
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
use App\Support\Geo\LocationReading;
use Carbon\Carbon;
use Database\Factories\AttendanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Check-in through AttendanceWorkflow, the only writer of attendance.
 *
 * A row is one session. Check-in is refused only while a session of the
 * employee's is still open TODAY: sessions already closed today are not in
 * the way, and neither is one left open on an earlier day.
 *
 * The clock is frozen at a Riyadh wall-clock moment before every test, so
 * "today" and every stored timestamp are known exactly. Readings are
 * placed N metres due north of the company (see the fixtures), which puts
 * the radius line at a known centimetre.
 */
final class CheckInTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private AttendanceWorkflow $workflow;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 09:15:00');
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
    public function a_check_in_inside_the_radius_stores_the_server_time_and_what_the_server_saw(): void
    {
        $now = $this->freezeRiyadhClock('2026-09-02 08:47:13');
        $reading = $this->readingMetersFromCompany(82.37, accuracyMeters: 12.34);

        $attendance = $this->workflow->checkIn($this->employee, $reading);

        $this->assertDatabaseCount('attendances', 1);

        $stored = $attendance->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertSame($this->employee->id, $stored->user_id);
        $this->assertSame('2026-09-02', $stored->attendance_date->toDateString());
        $this->assertSame($now->toDateTimeString(), $stored->check_in_at->toDateTimeString());
        $this->assertEqualsWithDelta($reading->coordinates->latitude, (float) $stored->check_in_latitude, 1e-7);
        $this->assertEqualsWithDelta($reading->coordinates->longitude, (float) $stored->check_in_longitude, 1e-7);
        $this->assertSame('12.34', $stored->check_in_accuracy);
        $this->assertSame('82.37', $stored->check_in_distance_from_company);
        $this->assertNull($stored->check_out_at);
        $this->assertTrue($stored->isOpen());
        $this->assertSame(AttendanceStatus::CheckedIn, $stored->status());
    }

    #[Test]
    public function the_attendance_day_is_the_riyadh_calendar_day_not_utc(): void
    {
        // 01:30 in Riyadh is still the previous evening in UTC.
        $this->freezeRiyadhClock('2026-09-03 01:30:00');

        $attendance = $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $this->assertSame('2026-09-03', $attendance->attendance_date->toDateString());
        $this->assertSame('2026-09-03 01:30:00', $attendance->check_in_at->toDateTimeString());
    }

    #[Test]
    public function a_reading_exactly_on_the_radius_line_is_accepted(): void
    {
        $attendance = $this->workflow->checkIn($this->employee, $this->readingMetersFromCompany(150.0));

        $this->assertSame('150.00', $attendance->check_in_distance_from_company);
        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function a_reading_one_centimetre_outside_the_radius_is_rejected(): void
    {
        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingMetersFromCompany(150.01)),
        );

        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $rejection->reason);
        $this->assertStringContainsString('150', $rejection->getMessage());
        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function a_reading_clearly_outside_the_radius_is_rejected(): void
    {
        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingMetersFromCompany(500.0)),
        );

        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $rejection->reason);
        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function a_second_check_in_while_a_session_is_open_is_refused(): void
    {
        $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::AlreadyCheckedIn, $rejection->reason);
        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function a_second_session_is_allowed_after_checking_out_the_same_day(): void
    {
        // Leaving at noon and coming back at one is the whole point: the
        // return is a check-in like any other, verified by the geofence,
        // and it opens a second session on the same attendance day.
        $morning = $this->attendanceSession($this->employee, '08:00', '12:30');
        $this->freezeRiyadhClock('2026-09-02 13:05:00');

        $afternoon = $this->workflow->checkIn($this->employee, $this->readingMetersFromCompany(40.0));

        $this->assertDatabaseCount('attendances', 2);
        $this->assertNotSame($morning->id, $afternoon->id);
        $this->assertSame('2026-09-02', $afternoon->attendance_date->toDateString());
        $this->assertSame('2026-09-02 13:05:00', $afternoon->check_in_at->toDateTimeString());
        $this->assertSame(AttendanceStatus::CheckedIn, $afternoon->status());

        // The session that was closed at noon is untouched.
        $stored = $morning->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertFalse($stored->isOpen());
        $this->assertSame('2026-09-02 12:30:00', $stored->check_out_at?->toDateTimeString());
    }

    #[Test]
    public function a_third_session_is_allowed_after_the_second_is_closed(): void
    {
        $this->attendanceSession($this->employee, '08:00', '10:00');
        $this->attendanceSession($this->employee, '11:00', '13:00');
        $this->freezeRiyadhClock('2026-09-02 14:00:00');

        $third = $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $this->assertDatabaseCount('attendances', 3);
        $this->assertTrue($third->isOpen());
        $this->assertSame(3, Attendance::query()->forUser($this->employee)->count());
    }

    #[Test]
    public function an_open_session_from_yesterday_does_not_block_todays_check_in(): void
    {
        // The forgotten check-out stays forgotten - it is evidence of a day
        // that was never closed - but it is not a reason to refuse someone
        // standing at the door this morning.
        $yesterday = app(AttendanceCalendar::class)->today()->subDay();
        $forgotten = $this->checkedIn($this->employee, $yesterday);

        $today = $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $this->assertDatabaseCount('attendances', 2);
        $this->assertSame('2026-09-02', $today->attendance_date->toDateString());
        $this->assertSame(AttendanceStatus::CheckedIn, $today->status());

        $stored = $forgotten->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertTrue($stored->isOpen());
        $this->assertSame($yesterday->toDateString(), $stored->attendance_date->toDateString());
        $this->assertSame(AttendanceStatus::MissingCheckOut, $stored->status());
    }

    #[Test]
    public function an_inactive_account_cannot_check_in(): void
    {
        $inactive = $this->makeEmployee(status: UserStatus::Inactive);

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($inactive, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::InactiveAccount, $rejection->reason);
        $this->assertNull($rejection->verification);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function nothing_can_be_inside_a_radius_that_has_no_centre(): void
    {
        AttendanceSetting::current()->forceFill(['latitude' => null, 'longitude' => null])->save();

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingAtCompany()),
        );

        $this->assertSame(AttendanceRejectionReason::LocationNotConfigured, $rejection->reason);
        $this->assertInstanceOf(LocationVerification::class, $rejection->verification);
        $this->assertNull($rejection->verification->distanceMeters);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function the_accuracy_ceiling_is_inclusive(): void
    {
        $this->assertSame(100, config('attendance.max_accuracy_meters'));

        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingAtCompany(accuracyMeters: 100.01)),
        );

        $this->assertSame(AttendanceRejectionReason::InsufficientAccuracy, $rejection->reason);
        $this->assertDatabaseCount('attendances', 0);

        $attendance = $this->workflow->checkIn($this->employee, $this->readingAtCompany(accuracyMeters: 100.0));

        $this->assertSame('100.00', $attendance->check_in_accuracy);
        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function a_rejection_for_distance_is_written_to_the_audit_table(): void
    {
        $reading = $this->readingMetersFromCompany(500.0, accuracyMeters: 7.5);

        $this->expectRejection(fn (): Attendance => $this->workflow->checkIn($this->employee, $reading));

        $this->assertDatabaseCount('attendance_rejections', 1);

        $audit = AttendanceRejection::query()->firstOrFail();

        $this->assertSame($this->employee->id, $audit->user_id);
        $this->assertSame(AttendanceAction::CheckIn, $audit->action);
        $this->assertEqualsWithDelta($reading->coordinates->latitude, (float) $audit->latitude, 1e-7);
        $this->assertEqualsWithDelta($reading->coordinates->longitude, (float) $audit->longitude, 1e-7);
        $this->assertSame('7.50', $audit->accuracy);
        $this->assertSame('500.00', $audit->distance_from_company);
        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $audit->reason);
    }

    #[Test]
    public function a_rejection_for_accuracy_is_written_to_the_audit_table(): void
    {
        $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingAtCompany(accuracyMeters: 650.0)),
        );

        $this->assertDatabaseCount('attendance_rejections', 1);

        $audit = AttendanceRejection::query()->firstOrFail();

        $this->assertSame(AttendanceAction::CheckIn, $audit->action);
        $this->assertSame('650.00', $audit->accuracy);
        $this->assertSame('0.00', $audit->distance_from_company);
        $this->assertSame(AttendanceRejectionReason::InsufficientAccuracy, $audit->reason);
    }

    #[Test]
    public function a_state_rule_rejection_is_not_audited(): void
    {
        // A duplicate tap is noise, not evidence of someone trying from
        // elsewhere.
        $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $this->expectRejection(fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingAtCompany()));

        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function the_exception_carries_the_reason_and_the_verification(): void
    {
        $rejection = $this->expectRejection(
            fn (): Attendance => $this->workflow->checkIn($this->employee, $this->readingMetersFromCompany(150.01, accuracyMeters: 9.0)),
        );

        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $rejection->reason);
        $this->assertInstanceOf(LocationVerification::class, $rejection->verification);
        $this->assertSame(150.01, $rejection->verification->roundedDistance());
        $this->assertSame(150, $rejection->verification->allowedRadiusMeters);
        $this->assertSame(9.0, $rejection->verification->accuracyMeters);
        $this->assertSame(100.0, $rejection->verification->maxAccuracyMeters);
        $this->assertSame($rejection->reason->message($rejection->verification), $rejection->getMessage());
    }

    #[Test]
    public function a_wider_radius_accepts_a_reading_the_default_would_refuse(): void
    {
        $this->configureCompanyLocation(radiusMeters: 300);

        $attendance = $this->workflow->checkIn($this->employee, $this->readingMetersFromCompany(250.0));

        $this->assertSame('250.00', $attendance->check_in_distance_from_company);
    }

    #[Test]
    public function moving_the_company_moves_the_radius_with_it(): void
    {
        $reading = $this->readingMetersFromCompany(100.0);
        $colleague = $this->makeEmployee();

        $attendance = $this->workflow->checkIn($this->employee, $reading);

        $this->assertSame('100.00', $attendance->check_in_distance_from_company);

        // The company moves 300 m north: the same device is now 200 m south
        // of it and outside the 150 m radius.
        $this->configureCompanyLocation(latitude: $this->coordinatesMetersFromCompany(300.0)->latitude);

        $rejection = $this->expectRejection(fn (): Attendance => $this->workflow->checkIn($colleague, $reading));

        $this->assertSame(AttendanceRejectionReason::OutsideAllowedArea, $rejection->reason);
        $this->assertInstanceOf(LocationVerification::class, $rejection->verification);
        $this->assertSame(200.0, $rejection->verification->roundedDistance());
    }

    #[Test]
    public function the_stored_time_is_the_servers_frozen_clock(): void
    {
        // The reading carries coordinates and accuracy only; there is no
        // device time to be trusted. Whatever the phone's clock says, the
        // record holds the instant the server decided.
        $now = $this->freezeRiyadhClock('2026-09-02 07:58:41');
        $reading = LocationReading::make(AttendanceFactory::COMPANY_LATITUDE, AttendanceFactory::COMPANY_LONGITUDE, 10.0);

        $attendance = $this->workflow->checkIn($this->employee, $reading);

        $this->assertTrue($attendance->check_in_at->equalTo($now));
        $this->assertSame('2026-09-02 07:58:41', $attendance->fresh()?->check_in_at->toDateTimeString());
    }

    #[Test]
    public function the_attendance_day_rolls_over_at_midnight_in_riyadh(): void
    {
        $this->freezeRiyadhClock('2026-09-02 23:59:00');

        $yesterday = $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $this->assertSame('2026-09-02', $yesterday->attendance_date->toDateString());

        $this->freezeRiyadhClock('2026-09-03 00:01:00');

        $rejection = $this->expectRejection(fn (): Attendance => $this->workflow->checkOut($this->employee, $this->readingAtCompany()));

        $this->assertSame(AttendanceRejectionReason::NotCheckedIn, $rejection->reason);

        $today = $this->workflow->checkIn($this->employee, $this->readingAtCompany());

        $this->assertSame('2026-09-03', $today->attendance_date->toDateString());
        $this->assertSame('2026-09-03 00:01:00', $today->check_in_at->toDateTimeString());
        $this->assertDatabaseCount('attendances', 2);
        $this->assertSame(AttendanceStatus::MissingCheckOut, $yesterday->fresh()?->status());
    }

    #[Test]
    public function timestamps_are_kept_in_the_riyadh_timezone(): void
    {
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame('Asia/Riyadh', date_default_timezone_get());

        $attendance = $this->workflow->checkIn($this->employee, $this->readingAtCompany());
        $stored = $attendance->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertSame('Asia/Riyadh', $attendance->check_in_at->timezoneName);
        $this->assertSame('Asia/Riyadh', $stored->check_in_at->timezoneName);
        $this->assertSame('2026-09-02 09:15:00', $stored->check_in_at->toDateTimeString());
    }

    /**
     * Runs the operation and returns the rejection it must raise, so a test
     * can inspect the reason and the verification instead of only the type.
     *
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
