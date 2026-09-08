<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Data\Leave\LeaveDraft;
use App\Enums\LeaveRefusalReason;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\Leave\LeaveRequestRefusedException;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceWorkflow;
use App\Services\Leave\LeaveConflicts;
use App\Services\Leave\LeaveRequestWorkflow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The leave domain: filing a request, deciding one, and the two figures an
 * administrator is shown before deciding.
 *
 * The rule this file spends most of its assertions on is the one with no
 * database backstop: days already spoken for. MariaDB 10.4 has no exclusion
 * constraint, so overlap is refused by the service under a lock and checked
 * a second time at approval, and both refusals are asserted here.
 *
 * The other thing proven repeatedly is a negative. Approved leave writes
 * nothing to `attendances`, creates no session, closes none, and does not
 * stop an employee who comes in anyway from recording an ordinary day. A
 * leave day looks like a day with no row; the absence is the record.
 */
final class LeaveRequestWorkflowTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private LeaveRequestWorkflow $workflow;

    private User $employee;

    private User $admin;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = $this->freezeRiyadhClock('2026-09-15 10:00:00')->startOfDay();

        $this->workflow = app(LeaveRequestWorkflow::class);
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
        $submittedAt = $this->freezeRiyadhClock('2026-09-15 10:41:07');

        $request = $this->workflow->submit($this->employee, $this->draft(reason: '  سفر عائلي مرتّب مسبقًا.  '));

        $stored = $request->fresh();

        $this->assertInstanceOf(LeaveRequest::class, $stored);
        $this->assertSame($this->employee->id, $stored->user_id);
        $this->assertSame(LeaveType::Annual, $stored->type);
        $this->assertSame('2026-09-20', $stored->starts_on->toDateString());
        $this->assertSame('2026-09-24', $stored->ends_on->toDateString());
        $this->assertSame(5, $stored->dayCount());
        $this->assertFalse($stored->is_exit_and_return);
        $this->assertSame('سفر عائلي مرتّب مسبقًا.', $stored->reason);
        $this->assertSame(RequestStatus::Pending, $stored->status);
        $this->assertSame($submittedAt->toDateTimeString(), $stored->submitted_at->toDateTimeString());
        $this->assertNull($stored->decided_by_id);
        $this->assertNull($stored->decided_at);
        $this->assertNull($stored->attachment_path);
        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function an_inactive_account_cannot_request_leave(): void
    {
        $suspended = $this->makeEmployee(status: UserStatus::Inactive);

        $refusal = $this->expectRefusal(fn (): LeaveRequest => $this->workflow->submit($suspended, $this->draft()));

        $this->assertSame(LeaveRefusalReason::AccountNotActive, $refusal->reason);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    #[Test]
    public function an_end_date_before_its_start_is_refused(): void
    {
        $refusal = $this->expectRefusal(
            fn (): LeaveRequest => $this->workflow->submit(
                $this->employee,
                $this->draft(from: $this->today->addDays(5), until: $this->today->addDays(2)),
            ),
        );

        $this->assertSame(LeaveRefusalReason::EndBeforeStart, $refusal->reason);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    #[Test]
    public function leave_that_started_within_the_last_thirty_days_may_still_be_reported(): void
    {
        $thirtyDaysAgo = $this->today->subDays(30);

        $reported = $this->workflow->submit(
            $this->employee,
            $this->draft(from: $thirtyDaysAgo, until: $thirtyDaysAgo, type: LeaveType::Sick),
        );

        $this->assertSame($thirtyDaysAgo->toDateString(), $reported->starts_on->toDateString());
        $this->assertSame(1, $reported->dayCount());
    }

    #[Test]
    public function leave_that_started_longer_ago_than_that_is_out_of_range(): void
    {
        $refusal = $this->expectRefusal(
            fn (): LeaveRequest => $this->workflow->submit(
                $this->employee,
                $this->draft(from: $this->today->subDays(31), until: $this->today->subDays(31)),
            ),
        );

        $this->assertSame(LeaveRefusalReason::DateOutOfRange, $refusal->reason);
    }

    #[Test]
    public function leave_starting_more_than_a_year_from_today_is_out_of_range(): void
    {
        $refusal = $this->expectRefusal(
            fn (): LeaveRequest => $this->workflow->submit(
                $this->employee,
                $this->draft(from: $this->today->addYear()->addDay(), until: $this->today->addYear()->addDay()),
            ),
        );

        $this->assertSame(LeaveRefusalReason::DateOutOfRange, $refusal->reason);
    }

    #[Test]
    public function a_request_may_cover_the_maximum_span_but_not_one_day_more(): void
    {
        $longest = $this->workflow->submit(
            $this->employee,
            $this->draft(from: $this->today, until: $this->today->addDays(LeaveRequest::MAX_DAYS - 1)),
        );

        $this->assertSame(LeaveRequest::MAX_DAYS, $longest->dayCount());

        $colleague = $this->makeEmployee();

        $refusal = $this->expectRefusal(
            fn (): LeaveRequest => $this->workflow->submit(
                $colleague,
                $this->draft(from: $this->today, until: $this->today->addDays(LeaveRequest::MAX_DAYS)),
            ),
        );

        $this->assertSame(LeaveRefusalReason::TooLong, $refusal->reason);
        $this->assertStringContainsString((string) LeaveRequest::MAX_DAYS, $refusal->getMessage());
        $this->assertDatabaseCount('leave_requests', 1);
    }

    #[Test]
    public function days_this_employee_has_already_asked_for_cannot_be_asked_for_twice(): void
    {
        $this->workflow->submit($this->employee, $this->draft(from: $this->today->addDays(5), until: $this->today->addDays(9)));

        $refusal = $this->expectRefusal(
            fn (): LeaveRequest => $this->workflow->submit(
                $this->employee,
                $this->draft(from: $this->today->addDays(9), until: $this->today->addDays(12)),
            ),
        );

        $this->assertSame(LeaveRefusalReason::Overlapping, $refusal->reason);
        $this->assertDatabaseCount('leave_requests', 1);
    }

    #[Test]
    public function ending_on_one_day_and_starting_on_the_next_is_two_requests_and_not_a_clash(): void
    {
        $first = $this->workflow->submit($this->employee, $this->draft(from: $this->today->addDays(5), until: $this->today->addDays(9)));
        $second = $this->workflow->submit($this->employee, $this->draft(from: $this->today->addDays(10), until: $this->today->addDays(12)));

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('leave_requests', 2);
    }

    #[Test]
    public function two_employees_asking_for_the_same_days_is_not_a_clash(): void
    {
        $colleague = $this->makeEmployee();

        $this->workflow->submit($this->employee, $this->draft());
        $theirs = $this->workflow->submit($colleague, $this->draft());

        $this->assertSame($colleague->id, $theirs->user_id);
        $this->assertDatabaseCount('leave_requests', 2);
    }

    #[Test]
    public function approving_stamps_the_decision_and_writes_nothing_to_attendances(): void
    {
        $decidedAt = $this->freezeRiyadhClock('2026-09-15 11:12:13');
        $request = $this->workflow->submit($this->employee, $this->draft());

        $approved = $this->workflow->approve($request, $this->admin, '  مقبول، بالتوفيق.  ');

        $this->assertSame(RequestStatus::Approved, $approved->status);
        $this->assertSame($this->admin->id, $approved->decided_by_id);
        $this->assertSame($decidedAt->toDateTimeString(), $approved->decided_at?->toDateTimeString());
        $this->assertSame('مقبول، بالتوفيق.', $approved->decision_note);
        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function an_approval_may_honestly_carry_no_note(): void
    {
        $request = $this->workflow->submit($this->employee, $this->draft());

        $approved = $this->workflow->approve($request, $this->admin);

        $this->assertSame(RequestStatus::Approved, $approved->status);
        $this->assertNull($approved->decision_note);
    }

    #[Test]
    public function overlap_is_refused_a_second_time_when_the_queue_is_worked_in_the_wrong_order(): void
    {
        // Both requests exist before either is decided - the state a queue
        // can reach when two administrators, or one administrator and a
        // rule added later, are working through it.
        $first = $this->leaveRequest($this->employee, $this->today->addDays(5), $this->today->addDays(9));
        $second = $this->leaveRequest($this->employee, $this->today->addDays(7), $this->today->addDays(12));

        $this->workflow->approve($first, $this->admin);

        $refusal = $this->expectRefusal(fn (): LeaveRequest => $this->workflow->approve($second, $this->admin));

        $this->assertSame(LeaveRefusalReason::Overlapping, $refusal->reason);
        $this->assertSame(RequestStatus::Pending, $second->fresh()?->status);
    }

    #[Test]
    public function a_request_the_administrator_will_not_grant_can_still_be_rejected_over_days_already_agreed(): void
    {
        $first = $this->leaveRequest($this->employee, $this->today->addDays(5), $this->today->addDays(9));
        $second = $this->leaveRequest($this->employee, $this->today->addDays(7), $this->today->addDays(12));

        $this->workflow->approve($first, $this->admin);

        $rejected = $this->workflow->reject($second, $this->admin, 'هذه الأيام معتمدة في طلب سابق.');

        $this->assertSame(RequestStatus::Rejected, $rejected->status);
        $this->assertSame('هذه الأيام معتمدة في طلب سابق.', $rejected->decision_note);
    }

    #[Test]
    public function a_rejection_with_no_reason_is_refused_by_the_service_and_not_only_by_the_form(): void
    {
        $request = $this->workflow->submit($this->employee, $this->draft());

        $this->expectException(InvalidArgumentException::class);

        $this->workflow->reject($request, $this->admin, '  ');
    }

    #[Test]
    public function a_decided_request_is_never_decided_again(): void
    {
        $request = $this->workflow->submit($this->employee, $this->draft());
        $this->workflow->approve($request, $this->admin);

        $refusal = $this->expectRefusal(
            fn (): LeaveRequest => $this->workflow->reject($request, $this->admin, 'غيّرت رأيي.'),
        );

        $decided = $request->fresh();

        $this->assertSame(LeaveRefusalReason::AlreadyDecided, $refusal->reason);
        $this->assertSame(RequestStatus::Approved, $decided?->status);
        $this->assertSame($this->admin->id, $decided->decided_by_id);
    }

    #[Test]
    public function an_account_deleted_between_submission_and_approval_stops_the_decision(): void
    {
        $request = $this->workflow->submit($this->employee, $this->draft());

        $this->employee->delete();

        $refusal = $this->expectRefusal(fn (): LeaveRequest => $this->workflow->approve($request, $this->admin));

        $this->assertSame(LeaveRefusalReason::RequesterAccountDeleted, $refusal->reason);
        $this->assertSame(RequestStatus::Pending, $request->fresh()?->status);
    }

    #[Test]
    public function an_employee_on_approved_leave_who_comes_in_anyway_records_an_ordinary_session(): void
    {
        $this->configureCompanyLocation();

        $request = $this->workflow->submit($this->employee, $this->draft(from: $this->today, until: $this->today->addDays(2)));
        $approved = $this->workflow->approve($request, $this->admin);

        $this->assertTrue($approved->coversDate($this->today));

        $session = app(AttendanceWorkflow::class)->checkIn($this->employee, $this->readingAtCompany());

        $this->assertSame($this->today->toDateString(), $session->attendance_date->toDateString());
        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function a_supporting_document_is_described_by_all_four_of_its_values_or_by_none(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeaveDraft(
            type: LeaveType::Sick,
            startsOn: $this->today,
            endsOn: $this->today,
            isExitAndReturn: false,
            reason: 'تقرير طبي مرفق.',
            attachmentPath: 'leave/2026/report.pdf',
        );
    }

    #[Test]
    public function a_request_carrying_a_supporting_document_stores_the_four_values_together(): void
    {
        $draft = new LeaveDraft(
            type: LeaveType::Sick,
            startsOn: $this->today,
            endsOn: $this->today,
            isExitAndReturn: false,
            reason: 'تقرير طبي مرفق.',
            attachmentPath: 'leave/2026/report.pdf',
            attachmentName: 'تقرير.pdf',
            attachmentSize: 20480,
            attachmentMimeType: 'application/pdf',
        );

        $this->assertTrue($draft->hasAttachment());

        $stored = $this->workflow->submit($this->employee, $draft)->fresh();

        $this->assertSame('leave/2026/report.pdf', $stored?->attachment_path);
        $this->assertSame('تقرير.pdf', $stored->attachment_name);
        $this->assertSame(20480, $stored->attachment_size);
        $this->assertSame('application/pdf', $stored->attachment_mime_type);
    }

    #[Test]
    public function leaving_and_coming_back_collapses_the_range_to_the_single_day_it_describes(): void
    {
        $draft = LeaveDraft::fromFormData([
            'type' => LeaveType::Annual->value,
            'starts_on' => '2026-09-20',
            'ends_on' => '2026-09-24',
            'is_exit_and_return' => true,
            'reason' => 'موعد رسمي في الصباح.',
        ]);

        $this->assertSame('2026-09-20', $draft->endsOn->toDateString());
        $this->assertSame(1, $draft->dayCount());

        $stored = $this->workflow->submit($this->employee, $draft);

        $this->assertTrue($stored->is_exit_and_return);
        $this->assertSame(1, $stored->dayCount());
    }

    #[Test]
    public function the_days_somebody_actually_worked_inside_a_leave_range_are_reported_as_a_warning(): void
    {
        $conflicts = app(LeaveConflicts::class);

        $request = $this->leaveRequest($this->employee, $this->today, $this->today->addDays(2));

        $this->assertSame(0, $conflicts->attendedDays($request));

        // Two sessions on one day are still one day somebody came in.
        $this->attendanceSession($this->employee, '08:00', '12:00', on: $this->today);
        $this->attendanceSession($this->employee, '13:00', '17:00', on: $this->today);
        $this->attendanceSession($this->employee, '08:00', '17:00', on: $this->today->addDay());
        $this->attendanceSession($this->employee, '08:00', '17:00', on: $this->today->addDays(9));

        $this->assertSame(2, $conflicts->attendedDays($request));

        // A warning, never a refusal: half a day worked before going home
        // is ordinary, and the system does not overrule the person who was
        // there.
        $approved = $this->workflow->approve($request, $this->admin);

        $this->assertSame(RequestStatus::Approved, $approved->status);
    }

    #[Test]
    public function other_approved_leave_covering_the_same_days_is_named_in_the_conflicts_summary(): void
    {
        $conflicts = app(LeaveConflicts::class);

        $pending = $this->leaveRequest($this->employee, $this->today->addDays(7), $this->today->addDays(12));

        $this->assertNull($conflicts->overlapSummary($pending));

        $agreed = $this->leaveRequest($this->employee, $this->today->addDays(5), $this->today->addDays(9));
        $this->workflow->approve($agreed, $this->admin);

        $this->assertSame(
            $agreed->starts_on->toDateString().' → '.$agreed->ends_on->toDateString(),
            $conflicts->overlapSummary($pending),
        );
    }

    /**
     * A draft as the form would hand it over: five annual days next week.
     */
    private function draft(
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
        LeaveType $type = LeaveType::Annual,
        bool $isExitAndReturn = false,
        string $reason = 'سفر عائلي مرتّب مسبقًا.',
    ): LeaveDraft {
        $startsOn = $from ?? $this->today->addDays(5);

        return new LeaveDraft(
            type: $type,
            startsOn: $startsOn,
            endsOn: $until ?? $startsOn->addDays(4),
            isExitAndReturn: $isExitAndReturn,
            reason: trim($reason),
        );
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    private function expectRefusal(Closure $operation): LeaveRequestRefusedException
    {
        try {
            $operation();
        } catch (LeaveRequestRefusedException $refusal) {
            $this->assertNotSame('', $refusal->getMessage());

            return $refusal;
        }

        $this->fail('The leave workflow accepted something it should have refused.');
    }
}
