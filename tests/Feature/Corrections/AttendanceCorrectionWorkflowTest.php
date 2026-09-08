<?php

declare(strict_types=1);

namespace Tests\Feature\Corrections;

use App\Data\Attendance\CorrectionDraft;
use App\Enums\CorrectionReason;
use App\Enums\CorrectionRefusalReason;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\Attendance\AttendanceCorrectionRefusedException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use App\Services\Attendance\CorrectionQuota;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Correction requests, from the form to the amended row.
 *
 * The approval half of this file is the most consequential code in the
 * product: it is the only thing that changes a row in `attendances`, the
 * table every other rule treats as evidence. So the promises are asserted
 * one at a time - the device's moment is archived before the corrected one
 * is written, its coordinates are never touched, the row names the request
 * that amended it, a day can never end up with two open or two overlapping
 * sessions, and a refusal anywhere in the middle leaves the database
 * exactly as it was.
 *
 * The clock is frozen at a Riyadh wall-clock moment throughout, because
 * every rule here is about a day, a month boundary, or whether a moment has
 * arrived yet.
 */
final class AttendanceCorrectionWorkflowTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private AttendanceCorrectionWorkflow $workflow;

    private CorrectionQuota $quota;

    private User $employee;

    private User $admin;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = $this->freezeRiyadhClock('2026-09-15 18:00:00')->startOfDay();
        $this->configureCorrectionQuota(3);

        $this->workflow = app(AttendanceCorrectionWorkflow::class);
        $this->quota = app(CorrectionQuota::class);
        $this->employee = $this->makeEmployee();
        $this->admin = $this->makeAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_request_records_what_the_employee_stated_and_nothing_they_did_not(): void
    {
        $submittedAt = $this->freezeRiyadhClock('2026-09-15 18:22:41');

        // Built the way the form hands it over, so the normalisation the
        // page relies on - a padded note, a picker's 'H:i:s' - is proven
        // here rather than assumed.
        $draft = CorrectionDraft::fromFormData([
            'attendance_date' => '2026-09-15',
            'attendance_id' => null,
            'reason' => CorrectionReason::ForgotToRecord->value,
            'requested_check_in_at' => '08:00:00',
            'requested_check_out_at' => '17:00:00',
            'note' => '  نسيت التسجيل عند الوصول  ',
        ]);

        $request = $this->workflow->submit($this->employee, $draft);

        $stored = $request->fresh();

        $this->assertInstanceOf(AttendanceCorrection::class, $stored);
        $this->assertSame($this->employee->id, $stored->user_id);
        $this->assertSame('2026-09-15', $stored->attendance_date->toDateString());
        $this->assertSame(CorrectionReason::ForgotToRecord, $stored->reason);
        $this->assertSame('08:00:00', $stored->requested_check_in_time);
        $this->assertSame('17:00:00', $stored->requested_check_out_time);
        $this->assertSame('نسيت التسجيل عند الوصول', $stored->note);
        $this->assertSame(RequestStatus::Pending, $stored->status);
        $this->assertSame($submittedAt->toDateTimeString(), $stored->submitted_at->toDateTimeString());
        $this->assertNull($stored->decided_by_id);
        $this->assertNull($stored->decided_at);
        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function an_inactive_account_cannot_file_a_correction_request(): void
    {
        $suspended = $this->makeEmployee(status: UserStatus::Inactive);

        $refusal = $this->expectRefusal(fn (): AttendanceCorrection => $this->workflow->submit($suspended, $this->draft()));

        $this->assertSame(CorrectionRefusalReason::AccountNotActive, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function an_allowance_of_zero_switches_correction_requests_off_entirely(): void
    {
        $this->configureCorrectionQuota(0);

        $refusal = $this->expectRefusal(fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft()));

        $this->assertSame(CorrectionRefusalReason::CorrectionsDisabled, $refusal->reason);
        $this->assertSame(0, $this->quota->remainingFor($this->employee));
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function an_employee_who_has_spent_the_months_allowance_is_refused(): void
    {
        $this->configureCorrectionQuota(2);

        $this->workflow->submit($this->employee, $this->draft(on: $this->today->subDays(2)));
        $this->workflow->submit($this->employee, $this->draft(on: $this->today->subDay()));

        $this->assertSame(0, $this->quota->remainingFor($this->employee));

        $refusal = $this->expectRefusal(fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft()));

        $this->assertSame(CorrectionRefusalReason::QuotaExhausted, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 2);
    }

    #[Test]
    public function the_allowance_counts_submissions_and_not_approvals(): void
    {
        $this->configureCorrectionQuota(1);

        $spent = $this->workflow->submit($this->employee, $this->draft(on: $this->today->subDay()));
        $this->workflow->reject($spent, $this->admin, 'الوقت المطلوب لا يطابق ما لدينا.');

        $this->assertSame(0, $this->quota->remainingFor($this->employee));

        $refusal = $this->expectRefusal(fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft()));

        $this->assertSame(CorrectionRefusalReason::QuotaExhausted, $refusal->reason);
    }

    #[Test]
    public function the_allowance_is_counted_over_the_riyadh_month_and_never_over_the_last_thirty_days(): void
    {
        $this->configureCorrectionQuota(1);

        // The last minute of August, Riyadh time. The request belongs to
        // August whatever a UTC clock would have called that instant.
        $this->freezeRiyadhClock('2026-08-31 23:59:00');
        $august = $this->workflow->submit($this->employee, $this->draft(on: CarbonImmutable::parse('2026-08-31')));

        $this->assertSame(0, $this->quota->remainingFor($this->employee));

        $this->freezeRiyadhClock('2026-09-01 00:00:00');

        $this->assertSame(1, $this->quota->remainingFor($this->employee));
        $this->assertSame('2026-10-01 00:00:00', $this->quota->resetsOn()->toDateTimeString());

        $september = $this->workflow->submit($this->employee, $this->draft(on: CarbonImmutable::parse('2026-09-01')));

        $this->assertNotSame($august->id, $september->id);
        $this->assertSame(0, $this->quota->remainingFor($this->employee));
    }

    #[Test]
    public function a_day_that_has_not_happened_yet_cannot_be_corrected(): void
    {
        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft(on: $this->today->addDay())),
        );

        $this->assertSame(CorrectionRefusalReason::DayInTheFuture, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function the_correctable_window_reaches_back_to_the_first_of_last_month_and_no_further(): void
    {
        $firstOfLastMonth = CarbonImmutable::parse('2026-08-01');

        $accepted = $this->workflow->submit($this->employee, $this->draft(on: $firstOfLastMonth));

        $this->assertSame('2026-08-01', $accepted->attendance_date->toDateString());

        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit(
                $this->employee,
                $this->draft(on: $firstOfLastMonth->subDay()),
            ),
        );

        $this->assertSame(CorrectionRefusalReason::DateTooOld, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 1);
    }

    #[Test]
    public function a_request_naming_neither_time_asks_for_nothing_and_is_refused(): void
    {
        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft(checkIn: null, checkOut: null)),
        );

        $this->assertSame(CorrectionRefusalReason::TimeMissing, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function a_requested_check_out_earlier_than_its_check_in_is_refused_when_it_is_filed(): void
    {
        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft(checkIn: '17:00', checkOut: '08:00')),
        );

        $this->assertSame(CorrectionRefusalReason::CheckOutBeforeCheckIn, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function a_day_with_no_session_at_all_needs_both_times(): void
    {
        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft(checkOut: null)),
        );

        $this->assertSame(CorrectionRefusalReason::IncompleteNewSession, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function a_session_belonging_to_somebody_else_cannot_be_corrected(): void
    {
        $colleague = $this->makeEmployee();
        $theirs = $this->attendanceSession($colleague, '08:02', '17:04');

        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft(attendanceId: $theirs->id)),
        );

        $this->assertSame(CorrectionRefusalReason::SessionNotYours, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    #[Test]
    public function a_session_from_another_day_cannot_be_corrected_under_todays_date(): void
    {
        $yesterday = $this->attendanceSession($this->employee, '08:02', '17:04', on: $this->today->subDay());

        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft(attendanceId: $yesterday->id)),
        );

        $this->assertSame(CorrectionRefusalReason::SessionNotYours, $refusal->reason);
    }

    #[Test]
    public function a_second_pending_request_about_the_same_day_is_refused(): void
    {
        $this->workflow->submit($this->employee, $this->draft());

        $refusal = $this->expectRefusal(fn (): AttendanceCorrection => $this->workflow->submit($this->employee, $this->draft()));

        $this->assertSame(CorrectionRefusalReason::RequestAlreadyPending, $refusal->reason);
        $this->assertDatabaseCount('attendance_corrections', 1);
    }

    #[Test]
    public function a_decided_request_frees_the_day_so_the_employee_may_ask_again(): void
    {
        $first = $this->workflow->submit($this->employee, $this->draft());
        $this->workflow->reject($first, $this->admin, 'الوقت المطلوب غير صحيح.');

        $second = $this->workflow->submit($this->employee, $this->draft());

        $this->assertSame(RequestStatus::Pending, $second->status);
        $this->assertDatabaseCount('attendance_corrections', 2);
    }

    #[Test]
    public function approving_a_forgotten_check_out_fills_it_and_archives_nothing(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02');
        $request = $this->correctionRequest($this->employee, $session, checkOut: '17:00');

        $amended = $this->workflow->approve($request, $this->admin);

        $this->assertSame('2026-09-15 17:00:00', $amended->check_out_at?->toDateTimeString());
        $this->assertSame('2026-09-15 08:02:00', $amended->check_in_at->toDateTimeString());

        // The device recorded no check-out at all, so there is nothing to
        // archive: this is a moment recorded by hand, not a moved one.
        $this->assertNull($amended->original_check_out_at);
        $this->assertNull($amended->original_check_in_at);
        $this->assertFalse($amended->hasDeviceCheckOut());
        $this->assertNull($amended->deviceCheckOutAt());

        $this->assertSame($request->id, $amended->check_out_correction_id);
        $this->assertNull($amended->check_in_correction_id);
        $this->assertFalse($amended->isOpen());
    }

    #[Test]
    public function approving_a_correction_of_a_device_moment_archives_the_original_before_writing_the_new_one(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        $amended = $this->workflow->approve($request, $this->admin, ' وصل مبكرًا وسجّل متأخرًا ');

        $this->assertSame('2026-09-15 07:30:00', $amended->check_in_at->toDateTimeString());
        $this->assertSame('2026-09-15 08:02:00', $amended->original_check_in_at?->toDateTimeString());
        $this->assertSame('2026-09-15 08:02:00', $amended->deviceCheckInAt()?->toDateTimeString());
        $this->assertSame($request->id, $amended->check_in_correction_id);

        // The check-out half was never asked about and is untouched.
        $this->assertSame('2026-09-15 17:04:00', $amended->check_out_at?->toDateTimeString());
        $this->assertNull($amended->original_check_out_at);
        $this->assertNull($amended->check_out_correction_id);

        $decided = $request->fresh();

        $this->assertInstanceOf(AttendanceCorrection::class, $decided);
        $this->assertSame(RequestStatus::Approved, $decided->status);
        $this->assertSame($this->admin->id, $decided->decided_by_id);
        $this->assertSame($session->id, $decided->attendance_id);
        $this->assertSame('وصل مبكرًا وسجّل متأخرًا', $decided->decision_note);
        $this->assertSame($this->today->setTime(18, 0)->toDateTimeString(), $decided->decided_at?->toDateTimeString());
    }

    #[Test]
    public function the_coordinates_accuracy_and_distance_of_the_original_reading_are_never_rewritten(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $before = $session->fresh();

        $this->assertInstanceOf(Attendance::class, $before);

        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30', checkOut: '17:30');
        $amended = $this->workflow->approve($request, $this->admin);

        $this->assertSame($before->check_in_latitude, $amended->check_in_latitude);
        $this->assertSame($before->check_in_longitude, $amended->check_in_longitude);
        $this->assertSame($before->check_in_accuracy, $amended->check_in_accuracy);
        $this->assertSame($before->check_in_distance_from_company, $amended->check_in_distance_from_company);
        $this->assertSame($before->check_out_latitude, $amended->check_out_latitude);
        $this->assertSame($before->check_out_accuracy, $amended->check_out_accuracy);
        $this->assertSame($before->check_out_distance_from_company, $amended->check_out_distance_from_company);
        $this->assertTrue($amended->hasDeviceCheckIn());
    }

    #[Test]
    public function a_second_correction_of_the_same_moment_moves_the_time_again_and_keeps_the_first_archive(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');

        $first = $this->correctionRequest($this->employee, $session, checkIn: '07:30');
        $this->workflow->approve($first, $this->admin);

        $second = $this->correctionRequest($this->employee, $session, checkIn: '07:00', on: $this->today);
        $amended = $this->workflow->approve($second, $this->admin);

        $this->assertSame('2026-09-15 07:00:00', $amended->check_in_at->toDateTimeString());
        $this->assertSame('2026-09-15 08:02:00', $amended->original_check_in_at?->toDateTimeString());
        $this->assertSame($second->id, $amended->check_in_correction_id);
    }

    #[Test]
    public function a_day_with_no_session_gets_one_carrying_both_correction_ids_and_no_reading(): void
    {
        $request = $this->correctionRequest($this->employee, checkIn: '09:00', checkOut: '16:00');

        $created = $this->workflow->approve($request, $this->admin);

        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame($this->employee->id, $created->user_id);
        $this->assertSame('2026-09-15', $created->attendance_date->toDateString());
        $this->assertSame('2026-09-15 09:00:00', $created->check_in_at->toDateTimeString());
        $this->assertSame('2026-09-15 16:00:00', $created->check_out_at?->toDateTimeString());
        $this->assertSame($request->id, $created->check_in_correction_id);
        $this->assertSame($request->id, $created->check_out_correction_id);
        $this->assertNull($created->original_check_in_at);
        $this->assertNull($created->original_check_out_at);
        $this->assertNull($created->check_in_latitude);
        $this->assertNull($created->check_in_distance_from_company);
        $this->assertNull($created->check_out_latitude);
        $this->assertFalse($created->hasDeviceCheckIn());
        $this->assertTrue($created->isCorrected());

        $this->assertSame($created->id, $request->fresh()?->attendance_id);
    }

    #[Test]
    public function a_moment_in_the_future_is_judged_at_approval_and_never_at_submission(): void
    {
        $this->freezeRiyadhClock('2026-09-15 08:10:00');
        $session = $this->attendanceSession($this->employee, '08:02');

        // Filed in the morning, asking for a check-out this evening. The
        // request is perfectly acceptable; it is the row it would write
        // that must not be in the future.
        $request = $this->workflow->submit(
            $this->employee,
            $this->draft(checkIn: null, checkOut: '17:00', attendanceId: $session->id),
        );

        $this->freezeRiyadhClock('2026-09-15 09:00:00');

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::MomentInTheFuture, $refusal->reason);
        $this->assertSame(RequestStatus::Pending, $request->fresh()?->status);
        $this->assertNull($session->fresh()?->check_out_at);

        $this->freezeRiyadhClock('2026-09-15 17:30:00');

        $amended = $this->workflow->approve($request, $this->admin);

        $this->assertSame('2026-09-15 17:00:00', $amended->check_out_at?->toDateTimeString());
    }

    #[Test]
    public function a_check_out_can_never_land_before_its_check_in(): void
    {
        $session = $this->attendanceSession($this->employee, '13:00', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkOut: '11:00');

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::CheckOutBeforeCheckIn, $refusal->reason);
        $this->assertSame('2026-09-15 17:04:00', $session->fresh()?->check_out_at?->toDateTimeString());
    }

    #[Test]
    public function a_correction_asking_for_the_time_already_recorded_is_refused(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '08:02', checkOut: '17:04');

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::NothingToChange, $refusal->reason);
        $this->assertNull($session->fresh()?->check_in_correction_id);
    }

    #[Test]
    public function a_half_that_asks_for_the_time_already_recorded_is_left_uncorrected(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '08:02', checkOut: '18:00');

        $amended = $this->workflow->approve($request, $this->admin);

        $this->assertFalse($amended->isCheckInCorrected());
        $this->assertNull($amended->original_check_in_at);
        $this->assertTrue($amended->isCheckOutCorrected());
        $this->assertSame('2026-09-15 17:04:00', $amended->original_check_out_at?->toDateTimeString());
    }

    #[Test]
    public function a_correction_that_would_leave_the_day_with_two_open_sessions_is_refused_by_the_service(): void
    {
        $open = $this->attendanceSession($this->employee, '13:00');

        // A request naming no session and only a check-in would manufacture
        // a second open session. submit() will not file one; the workflow
        // refuses it here rather than letting the unique index be the
        // messenger for an administrator's deliberate press.
        $request = $this->correctionRequest($this->employee, checkIn: '08:00');

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::TwoOpenSessions, $refusal->reason);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame(RequestStatus::Pending, $request->fresh()?->status);
        $this->assertNull($open->fresh()?->check_out_at);
    }

    #[Test]
    public function a_correction_that_would_overlap_another_session_of_the_same_day_is_refused_by_the_service(): void
    {
        $morning = $this->attendanceSession($this->employee, '08:00', '12:00');
        $afternoon = $this->attendanceSession($this->employee, '13:00', '17:00');

        $request = $this->correctionRequest($this->employee, $afternoon, checkIn: '11:00');

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::OverlappingSession, $refusal->reason);
        $this->assertSame('2026-09-15 13:00:00', $afternoon->fresh()?->check_in_at->toDateTimeString());
        $this->assertSame('2026-09-15 12:00:00', $morning->fresh()?->check_out_at?->toDateTimeString());
    }

    #[Test]
    public function a_session_that_touches_another_without_running_into_it_is_accepted(): void
    {
        $this->attendanceSession($this->employee, '08:00', '12:00');
        $afternoon = $this->attendanceSession($this->employee, '13:00', '17:00');

        $request = $this->correctionRequest($this->employee, $afternoon, checkIn: '12:00');

        $amended = $this->workflow->approve($request, $this->admin);

        $this->assertSame('2026-09-15 12:00:00', $amended->check_in_at->toDateTimeString());
    }

    #[Test]
    public function one_of_several_closed_sessions_can_be_corrected_without_touching_the_others(): void
    {
        $morning = $this->attendanceSession($this->employee, '08:00', '12:00');
        $afternoon = $this->attendanceSession($this->employee, '13:00', '17:00');
        $evening = $this->attendanceSession($this->employee, '19:00', '21:00');

        $request = $this->correctionRequest($this->employee, $afternoon, checkOut: '17:45');
        $this->workflow->approve($request, $this->admin);

        $this->assertSame('2026-09-15 17:45:00', $afternoon->fresh()?->check_out_at?->toDateTimeString());
        $this->assertNull($morning->fresh()?->check_out_correction_id);
        $this->assertNull($evening->fresh()?->check_in_correction_id);
        $this->assertDatabaseCount('attendances', 3);
    }

    #[Test]
    public function a_request_whose_session_is_no_longer_on_that_day_cannot_be_approved(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        // The audit rows point at the session by key; moving the session to
        // another day is the only way it can leave the day the request is
        // about, and the workflow must notice rather than amend a stranger.
        $session->forceFill(['attendance_date' => $this->today->subDay()->toDateString()])->save();

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::SessionNotYours, $refusal->reason);
        $this->assertSame(RequestStatus::Pending, $request->fresh()?->status);
    }

    #[Test]
    public function approving_twice_does_nothing_the_second_time(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        $this->workflow->approve($request, $this->admin);

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::AlreadyDecided, $refusal->reason);
        $this->assertSame('2026-09-15 08:02:00', $session->fresh()?->original_check_in_at?->toDateTimeString());
    }

    #[Test]
    public function two_administrators_pressing_approve_on_the_same_request_decide_it_once(): void
    {
        $second = $this->makeAdmin();
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        // Both administrators opened the queue before either pressed, so
        // each holds their own instance of the same row.
        $forFirst = AttendanceCorrection::query()->firstOrFail();
        $forSecond = AttendanceCorrection::query()->firstOrFail();

        $this->workflow->approve($forFirst, $this->admin);

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($forSecond, $second));

        $this->assertSame(CorrectionRefusalReason::AlreadyDecided, $refusal->reason);

        $decided = $forFirst->fresh();

        $this->assertSame($this->admin->id, $decided?->decided_by_id);
        $this->assertSame('2026-09-15 08:02:00', $session->fresh()?->original_check_in_at?->toDateTimeString());
    }

    #[Test]
    public function an_account_deleted_between_submission_and_approval_stops_the_approval(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        $this->employee->delete();

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::RequesterAccountDeleted, $refusal->reason);
        $this->assertSame(RequestStatus::Pending, $request->fresh()?->status);
        $this->assertNull($session->fresh()?->check_in_correction_id);
    }

    #[Test]
    public function a_deactivated_account_does_not_block_an_approval_about_a_day_it_was_still_working(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        $this->employee->forceFill(['status' => UserStatus::Inactive])->save();

        $amended = $this->workflow->approve($request, $this->admin);

        $this->assertSame('2026-09-15 07:30:00', $amended->check_in_at->toDateTimeString());
    }

    #[Test]
    public function a_failure_in_the_middle_of_an_approval_leaves_the_database_exactly_as_it_was(): void
    {
        $logger = Log::spy();

        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        // The attendance row is amended first and the request is stamped
        // second, so failing on the second statement is a failure exactly
        // in the middle: if the two are not one transaction, the row stays
        // corrected while the request stays pending.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update `attendance_corrections`')) {
                throw new QueryException('mysql', $query->sql, [], new PDOException('forced mid-flight failure'));
            }
        });

        $refusal = $this->expectRefusal(fn (): Attendance => $this->workflow->approve($request, $this->admin));

        $this->assertSame(CorrectionRefusalReason::CouldNotBeApplied, $refusal->reason);

        $stored = $session->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertSame('2026-09-15 08:02:00', $stored->check_in_at->toDateTimeString());
        $this->assertNull($stored->original_check_in_at);
        $this->assertNull($stored->check_in_correction_id);
        $this->assertFalse($stored->isCorrected());

        $untouched = $request->fresh();

        $this->assertSame(RequestStatus::Pending, $untouched?->status);
        $this->assertNull($untouched->decided_by_id);
        $this->assertNull($untouched->decided_at);

        $logger->shouldHaveReceived('error')->once();
    }

    #[Test]
    public function rejecting_writes_nothing_to_attendances_and_keeps_the_note(): void
    {
        $session = $this->attendanceSession($this->employee, '08:02', '17:04');
        $request = $this->correctionRequest($this->employee, $session, checkIn: '07:30');

        $rejected = $this->workflow->reject($request, $this->admin, '  الوقت المطلوب لا يطابق ما لدينا.  ');

        $this->assertSame(RequestStatus::Rejected, $rejected->status);
        $this->assertSame($this->admin->id, $rejected->decided_by_id);
        $this->assertSame('الوقت المطلوب لا يطابق ما لدينا.', $rejected->decision_note);

        $stored = $session->fresh();

        $this->assertSame('2026-09-15 08:02:00', $stored?->check_in_at->toDateTimeString());
        $this->assertNull($stored->check_in_correction_id);
        $this->assertNull($stored->original_check_in_at);
    }

    #[Test]
    public function a_rejection_with_no_reason_is_refused_by_the_service_and_not_only_by_the_form(): void
    {
        $request = $this->correctionRequest($this->employee);

        $this->expectException(InvalidArgumentException::class);

        $this->workflow->reject($request, $this->admin, '   ');
    }

    #[Test]
    public function a_decided_request_is_never_decided_again(): void
    {
        $request = $this->correctionRequest($this->employee, checkIn: '09:00', checkOut: '16:00');

        $this->workflow->approve($request, $this->admin);

        $refusal = $this->expectRefusal(
            fn (): AttendanceCorrection => $this->workflow->reject($request, $this->admin, 'غيّرت رأيي.'),
        );

        $this->assertSame(CorrectionRefusalReason::AlreadyDecided, $refusal->reason);
        $this->assertSame(RequestStatus::Approved, $request->fresh()?->status);
    }

    /**
     * A draft as the form would hand it over: today, both times, and a
     * forgotten tap as the reason.
     */
    private function draft(
        ?string $checkIn = '08:00',
        ?string $checkOut = '17:00',
        ?CarbonImmutable $on = null,
        ?int $attendanceId = null,
        CorrectionReason $reason = CorrectionReason::ForgotToRecord,
        ?string $note = null,
    ): CorrectionDraft {
        return new CorrectionDraft(
            date: $on ?? $this->today,
            attendanceId: $attendanceId,
            reason: $reason,
            checkInTime: $checkIn,
            checkOutTime: $checkOut,
            note: $note,
        );
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    private function expectRefusal(Closure $operation): AttendanceCorrectionRefusedException
    {
        try {
            $operation();
        } catch (AttendanceCorrectionRefusedException $refusal) {
            $this->assertNotSame('', $refusal->getMessage());

            return $refusal;
        }

        $this->fail('The correction workflow accepted something it should have refused.');
    }
}
