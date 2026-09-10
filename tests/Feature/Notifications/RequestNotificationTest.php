<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Attendance\CorrectionDraft;
use App\Data\Leave\LeaveDraft;
use App\Data\Requests\RequestNotice;
use App\Enums\CorrectionReason;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Attendance\AttendanceCorrectionRefusedException;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\NewRequestNotification;
use App\Notifications\RequestDecisionNotification;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use App\Services\Leave\LeaveRequestWorkflow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Who is told, and what they are told, when a request is filed or answered.
 *
 * Two questions run through the whole file and neither is about a row
 * existing. The first is the audience: an administrator is not simply
 * `role = 'admin'`, a deleted account is nobody, the person who asked is not
 * an audience for their own question, and the account pinned in .env must be
 * indistinguishable from every other administrator - a bell that treated it
 * differently would name the one thing nothing in this interface may name.
 *
 * The second is the boundary. Approving a correction amends an attendance
 * row inside a transaction over a locked day. A notification that throws
 * must not reach back into that, and a decision that rolled back must not
 * be announced at all. Both directions are forced here rather than reasoned
 * about: NotificationSending is a framework event, and a listener on it that
 * throws is the closest thing to a `notifications` table that has gone away.
 */
final class RequestNotificationTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string SUPER_ADMIN = 'owner@company.test';

    private AttendanceCorrectionWorkflow $corrections;

    private LeaveRequestWorkflow $leave;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 09:00:00');
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(3);

        // Blank by default, so a test that says nothing about the pin is a
        // test about ordinary administrators.
        config(['admin.super_admin_email' => null]);

        $this->corrections = app(AttendanceCorrectionWorkflow::class);
        $this->leave = app(LeaveRequestWorkflow::class);
        $this->employee = $this->makeEmployee('sara@company.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A correction for yesterday, filed the way the employee's modal files
     * one.
     */
    private function fileCorrection(User $employee, ?CarbonImmutable $day = null): AttendanceCorrection
    {
        $day ??= CarbonImmutable::parse('2026-09-09');

        $session = $this->attendanceSession($employee, '08:00', '17:00', $day);

        return $this->corrections->submit($employee, new CorrectionDraft(
            date: $day,
            attendanceId: $session->getKey(),
            reason: CorrectionReason::ForgotToRecord,
            checkInTime: '07:30',
            checkOutTime: null,
            note: 'The door was busy.',
        ));
    }

    private function fileLeave(User $employee): LeaveRequest
    {
        return $this->leave->submit($employee, new LeaveDraft(
            type: LeaveType::Annual,
            startsOn: CarbonImmutable::parse('2026-09-20'),
            endsOn: CarbonImmutable::parse('2026-09-22'),
            isExitAndReturn: false,
            reason: 'A family matter.',
        ));
    }

    /**
     * The stored payloads in one person's bell, newest first.
     *
     * @return list<array<array-key, mixed>>
     */
    private function bellOf(User $user): array
    {
        return $user->notifications()
            ->get()
            ->map(static fn (object $row): array => (array) $row->data)
            ->all();
    }

    private function bellCountOf(User $user): int
    {
        return $user->notifications()->count();
    }

    /**
     * Makes writing a notification row fail, wherever it is attempted.
     *
     * NotificationSending is dispatched through `until()`, so a listener
     * that throws here throws from inside Notification::send() - which is
     * where a missing, locked or full table would throw too.
     */
    private function breakNotifications(): MockInterface
    {
        Event::listen(NotificationSending::class, static function (): void {
            throw new RuntimeException('the notifications table is not there');
        });

        return Log::spy();
    }

    #[Test]
    public function every_administrator_is_told_when_a_correction_request_arrives(): void
    {
        $first = $this->makeAdmin('first@company.test');
        $second = $this->makeAdmin('second@company.test');
        $colleague = $this->makeEmployee('omar@company.test');

        $request = $this->fileCorrection($this->employee);

        foreach ([$first, $second] as $administrator) {
            $this->assertCount(1, $this->bellOf($administrator));
            $this->assertSame('correction', $this->bellOf($administrator)[0]['kind']);
            $this->assertSame($request->getKey(), $this->bellOf($administrator)[0]['request_id']);
            $this->assertSame(RequestStatus::Pending->value, $this->bellOf($administrator)[0]['status']);
        }

        // An employee is not an audience for somebody else's request, and
        // the requester is not an audience for their own.
        $this->assertSame(0, $this->bellCountOf($colleague));
        $this->assertSame(0, $this->bellCountOf($this->employee));
    }

    #[Test]
    public function every_administrator_is_told_when_a_leave_request_arrives(): void
    {
        $administrator = $this->makeAdmin('first@company.test');

        $request = $this->fileLeave($this->employee);

        $notice = $this->bellOf($administrator)[0];

        $this->assertSame('leave', $notice['kind']);
        $this->assertSame($request->getKey(), $notice['request_id']);
        $this->assertSame('2026-09-20', $notice['from']);
        $this->assertSame('2026-09-22', $notice['until']);
    }

    #[Test]
    public function an_administrator_is_not_told_about_their_own_request(): void
    {
        $author = $this->makeAdmin('author@company.test');
        $colleague = $this->makeAdmin('colleague@company.test');

        $this->fileCorrection($author);

        $this->assertSame(0, $this->bellCountOf($author));
        $this->assertSame(1, $this->bellCountOf($colleague));
    }

    #[Test]
    public function a_deleted_administrator_is_not_told(): void
    {
        $present = $this->makeAdmin('present@company.test');
        $gone = $this->makeAdmin('gone@company.test');

        $gone->delete();

        $this->fileCorrection($this->employee);

        $this->assertSame(1, $this->bellCountOf($present));
        $this->assertSame(0, $this->bellCountOf($gone));
    }

    #[Test]
    public function the_pinned_account_is_told_whatever_its_role_column_says(): void
    {
        config(['admin.super_admin_email' => self::SUPER_ADMIN]);

        $owner = $this->makeEmployee(self::SUPER_ADMIN);

        // The column says employee. The pin says otherwise, and the pin is
        // the whole point of being in .env.
        $this->assertSame(UserRole::Employee, $owner->role);
        $this->assertTrue($owner->isAdmin());

        $this->fileCorrection($this->employee);

        $this->assertSame(1, $this->bellCountOf($owner));
    }

    #[Test]
    public function the_pinned_account_receives_exactly_what_the_others_receive(): void
    {
        config(['admin.super_admin_email' => self::SUPER_ADMIN]);

        $owner = $this->makeEmployee(self::SUPER_ADMIN);
        $ordinary = $this->makeAdmin('ordinary@company.test');

        $this->fileCorrection($this->employee);

        // Same count and the same payload, byte for byte. Anything the
        // pinned account received that the others did not - an extra line, a
        // different wording, a different order - would answer the one
        // question the interface must never answer.
        $this->assertSame($this->bellOf($ordinary), $this->bellOf($owner));
    }

    #[Test]
    public function a_deactivated_administrator_is_still_told_so_the_pin_stays_hidden(): void
    {
        config(['admin.super_admin_email' => self::SUPER_ADMIN]);

        $owner = $this->makeEmployee(self::SUPER_ADMIN);
        $deactivated = $this->makeAdmin('away@company.test');

        $deactivated->forceFill(['status' => UserStatus::Inactive])->save();

        $this->fileCorrection($this->employee);

        // isActive() answers yes for the pinned account however the column
        // reads, so an audience filtered by status would notify the owner and
        // skip the colleague beside them - and the difference would be
        // visible in the queue. Both get the row; neither can read it until
        // they can sign in.
        $this->assertSame(1, $this->bellCountOf($owner));
        $this->assertSame(1, $this->bellCountOf($deactivated));
    }

    #[Test]
    public function the_employee_who_asked_is_told_when_a_correction_is_approved(): void
    {
        $administrator = $this->makeAdmin('first@company.test');
        $bystander = $this->makeEmployee('omar@company.test');

        $request = $this->fileCorrection($this->employee);
        $this->corrections->approve($request, $administrator, 'Checked with the door log.');

        $notice = $this->bellOf($this->employee)[0];

        $this->assertSame(RequestStatus::Approved->value, $notice['status']);
        $this->assertSame('correction', $notice['kind']);
        $this->assertSame('Checked with the door log.', $notice['note']);

        $this->assertSame(0, $this->bellCountOf($bystander));
    }

    #[Test]
    public function the_employee_who_asked_is_told_when_a_correction_is_rejected_and_reads_the_note(): void
    {
        $administrator = $this->makeAdmin('first@company.test');
        $bystander = $this->makeEmployee('omar@company.test');

        $request = $this->fileCorrection($this->employee);
        $this->corrections->reject($request, $administrator, 'The door log shows 08:00.');

        $notice = $this->bellOf($this->employee)[0];

        $this->assertSame(RequestStatus::Rejected->value, $notice['status']);

        // The note is the whole of what a refusal gives them, so it travels
        // in the row rather than being a reason to go and look.
        $this->assertSame('The door log shows 08:00.', $notice['note']);

        $this->assertSame(0, $this->bellCountOf($bystander));
    }

    #[Test]
    public function an_approval_with_no_note_carries_none(): void
    {
        $administrator = $this->makeAdmin('first@company.test');

        $request = $this->fileCorrection($this->employee);
        $this->corrections->approve($request, $administrator);

        $this->assertNull($this->bellOf($this->employee)[0]['note']);
    }

    #[Test]
    public function the_employee_who_asked_is_told_when_leave_is_decided_and_nobody_else_is(): void
    {
        $administrator = $this->makeAdmin('first@company.test');
        $bystander = $this->makeEmployee('omar@company.test');

        $request = $this->fileLeave($this->employee);
        $this->leave->approve($request, $administrator, 'Enjoy it.');

        $notice = $this->bellOf($this->employee)[0];

        $this->assertSame('leave', $notice['kind']);
        $this->assertSame(RequestStatus::Approved->value, $notice['status']);
        $this->assertSame('Enjoy it.', $notice['note']);

        $this->assertSame(0, $this->bellCountOf($bystander));

        // The administrator holds only the arrival notice; deciding it is
        // not news to the person who decided it.
        $this->assertCount(1, $this->bellOf($administrator));
        $this->assertSame(RequestStatus::Pending->value, $this->bellOf($administrator)[0]['status']);
    }

    #[Test]
    public function a_stored_notice_holds_facts_rather_than_the_language_it_was_written_in(): void
    {
        $administrator = $this->makeAdmin('first@company.test');

        App::setLocale('ar');
        $this->fileCorrection($this->employee);

        $payload = $this->bellOf($administrator)[0];

        // Nothing in the row is a sentence, so there is nothing in it that
        // could be in the wrong language when it is read.
        $this->assertSame(
            ['format', 'kind', 'request_id', 'employee_name', 'from', 'until', 'status', 'note'],
            array_keys($payload),
        );
        $this->assertSame('filament', $payload['format']);
    }

    #[Test]
    public function a_request_is_still_filed_when_notifying_throws(): void
    {
        $this->makeAdmin('first@company.test');
        $log = $this->breakNotifications();

        $request = $this->fileCorrection($this->employee);

        // The employee pressed Send; the request is what they asked for and
        // the bell is not.
        $this->assertTrue($request->exists);
        $this->assertDatabaseHas('attendance_corrections', ['id' => $request->getKey()]);
        $this->assertDatabaseCount('notifications', 0);

        // Swallowed, but not silently: something nobody was told about is
        // exactly the kind of failure that has to leave a trace.
        $log->shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function leave_is_still_filed_when_notifying_throws(): void
    {
        $this->makeAdmin('first@company.test');
        $this->breakNotifications();

        $request = $this->fileLeave($this->employee);

        $this->assertDatabaseHas('leave_requests', ['id' => $request->getKey()]);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[Test]
    public function an_amendment_survives_a_notification_that_throws(): void
    {
        $administrator = $this->makeAdmin('first@company.test');
        $request = $this->fileCorrection($this->employee);

        $this->breakNotifications();

        $amended = $this->corrections->approve($request, $administrator, 'Agreed.');

        // The transaction had already committed when the notification was
        // attempted, and the failure was swallowed, so the day is corrected
        // and the request is answered exactly as if the bell had worked.
        $this->assertSame('07:30', $amended->check_in_at->format('H:i'));
        $this->assertSame('08:00', $amended->original_check_in_at?->format('H:i'));
        $this->assertSame(RequestStatus::Approved, $request->fresh()?->status);

        // The administrator's arrival notice was written before the break;
        // the employee's answer is the one that could not be.
        $this->assertSame(0, $this->bellCountOf($this->employee));
    }

    #[Test]
    public function a_decision_that_was_rolled_back_is_announced_to_nobody(): void
    {
        $administrator = $this->makeAdmin('first@company.test');
        $day = CarbonImmutable::parse('2026-09-09');

        $request = $this->fileCorrection($this->employee, $day);

        // A second session on the same day that the requested 07:30 - 17:00
        // would now run straight through.
        $this->attendanceSession($this->employee, '07:00', '07:45', $day);

        $administrator->notifications()->delete();
        $this->employee->notifications()->delete();

        try {
            $this->corrections->approve($request, $administrator);
            $this->fail('The overlapping correction was applied.');
        } catch (AttendanceCorrectionRefusedException) {
            // The refusal is what this test is standing on.
        }

        // Nothing was written, so nothing is announced: the request is still
        // a question and telling the employee it had been answered would be
        // a lie the interface could not take back.
        $this->assertSame(RequestStatus::Pending, $request->fresh()?->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[Test]
    public function nothing_here_is_ever_emailed_or_queued(): void
    {
        $notice = RequestNotice::about($this->correctionRequest($this->employee));

        // Mail is not configured on this host and the queue has no worker, so
        // a second channel would be a promise nothing keeps - and ShouldQueue
        // would wrap one INSERT in a synchronous job for nothing.
        $this->assertSame(['database'], (new NewRequestNotification($notice))->via($this->employee));
        $this->assertSame(['database'], (new RequestDecisionNotification($notice))->via($this->employee));

        foreach ([NewRequestNotification::class, RequestDecisionNotification::class] as $class) {
            $this->assertNotContains(ShouldQueue::class, class_implements($class) ?: []);
        }
    }
}
