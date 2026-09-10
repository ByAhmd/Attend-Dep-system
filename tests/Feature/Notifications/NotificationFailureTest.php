<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Attendance\CorrectionDraft;
use App\Data\Leave\LeaveDraft;
use App\Data\Requests\RequestNotice;
use App\Enums\CorrectionReason;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Exceptions\Attendance\AttendanceCorrectionRefusedException;
use App\Filament\Notifications\RequestNotices;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use App\Services\Leave\LeaveRequestWorkflow;
use App\Support\Filament\RequestNoticeLink;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What happens when the bell cannot be rung, and what the bell says once the
 * thing it is about has moved on.
 *
 * The first half is the boundary. Two writes matter more than any
 * notification about them: a request an employee has just filed, and an
 * attendance row an approval has just amended inside a transaction over a
 * locked day. Both are on the disk before a listener runs, and nothing a
 * listener does may reach back and undo either - not an exception, and not
 * a database that has stopped accepting rows. The failure is forced at the
 * channel, which is the layer that actually writes, rather than at the event
 * above it: a missing table, a locked one and a full one all surface as a
 * QueryException from exactly there.
 *
 * The second half is time. A notice is a record of a moment, and the moment
 * passes: the request gets answered by the other administrator, the employee
 * leaves the company, the row is taken out of the database by hand. The line
 * in the bell outlives all three, and what must never happen is that
 * following it produces a stack trace. It does not - every one of those
 * links still answers 200 - and that is asserted here rather than assumed,
 * because it is the assumption a stored URL would have quietly broken.
 */
final class NotificationFailureTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private AttendanceCorrectionWorkflow $corrections;

    private LeaveRequestWorkflow $leave;

    private User $employee;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 09:00:00');
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(10);

        config(['admin.super_admin_email' => null]);

        $this->corrections = app(AttendanceCorrectionWorkflow::class);
        $this->leave = app(LeaveRequestWorkflow::class);

        $this->employee = $this->makeEmployee('sara@company.test');
        $this->first = $this->makeAdmin('first@company.test');
        $this->second = $this->makeAdmin('second@company.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Turns the notifications table into one that cannot be written to.
     *
     * The database channel is what performs the INSERT, and it is resolved
     * from the container every time the channel manager builds the 'database'
     * driver, so replacing it here puts the failure where a missing, locked
     * or full table would put it - inside Notification::send(), after the
     * event has been dispatched and after the row this is all about has
     * committed. The exception is the one MySQL raises for a table that is
     * not there, carried in the class Laravel wraps every driver error in.
     */
    private function makeNotificationsUnwritable(): void
    {
        $this->app->bind(DatabaseChannel::class, static fn (): DatabaseChannel => new class extends DatabaseChannel
        {
            public function send($notifiable, Notification $notification): Model
            {
                throw new QueryException(
                    'mysql',
                    'insert into `notifications` (`id`, `type`, `notifiable_type`, `notifiable_id`, `data`) values (?, ?, ?, ?, ?)',
                    [],
                    new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'attendance.notifications' doesn't exist"),
                );
            }
        });

        // The channel manager caches every driver the first time it builds
        // one, so the bind above would be ignored by any test that has
        // already sent a notification. Forgetting the manager makes it build
        // the database driver again, out of the container this has just
        // changed.
        $this->app->forgetInstance(ChannelManager::class);
        NotificationFacade::clearResolvedInstances();
    }

    private function fileCorrection(?CarbonImmutable $day = null): AttendanceCorrection
    {
        $day ??= CarbonImmutable::parse('2026-09-09');

        $session = $this->attendanceSession($this->employee, '08:00', '17:00', $day);

        return $this->corrections->submit($this->employee, new CorrectionDraft(
            date: $day,
            attendanceId: $session->getKey(),
            reason: CorrectionReason::ForgotToRecord,
            checkInTime: '07:30',
            checkOutTime: null,
            note: null,
        ));
    }

    private function bellCount(User $reader): int
    {
        return $reader->notifications()->count();
    }

    #[Test]
    public function a_request_is_filed_even_though_the_notifications_table_is_gone(): void
    {
        $this->makeNotificationsUnwritable();
        $log = Log::spy();

        $request = $this->fileCorrection();

        $this->assertTrue($request->exists);
        $this->assertDatabaseHas('attendance_corrections', ['id' => $request->getKey()]);
        $this->assertSame(0, $this->bellCount($this->first));

        $log->shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'could not be notified'))
            ->once();
    }

    #[Test]
    public function leave_is_filed_even_though_the_notifications_table_is_gone(): void
    {
        $this->makeNotificationsUnwritable();
        Log::spy();

        $request = $this->leave->submit($this->employee, new LeaveDraft(
            type: LeaveType::Annual,
            startsOn: CarbonImmutable::parse('2026-09-20'),
            endsOn: CarbonImmutable::parse('2026-09-22'),
            isExitAndReturn: false,
            reason: 'A family matter.',
        ));

        $this->assertDatabaseHas('leave_requests', ['id' => $request->getKey()]);
        $this->assertSame(0, $this->bellCount($this->first));
    }

    #[Test]
    public function an_amendment_stands_even_though_the_notifications_table_is_gone(): void
    {
        $request = $this->fileCorrection();

        $this->makeNotificationsUnwritable();
        $log = Log::spy();

        $amended = $this->corrections->approve($request, $this->first, 'Agreed.');

        // The whole point of the boundary: an attendance row amended inside a
        // committed transaction, with the notification about it thrown away.
        $this->assertInstanceOf(Attendance::class, $amended);
        $this->assertSame('07:30', $amended->check_in_at->format('H:i'));
        $this->assertSame($request->getKey(), $amended->check_in_correction_id);
        $this->assertSame(RequestStatus::Approved, $request->refresh()->status);
        $this->assertSame(0, $this->bellCount($this->employee));

        $log->shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function a_decision_stands_even_when_its_own_notice_is_too_large_for_the_column(): void
    {
        // Not a mock: `decision_note` is TEXT and takes 40,000 bytes of
        // Arabic quite happily, while the same 20,000 characters escaped into
        // the notification's JSON payload are 120,000 and will not fit the
        // TEXT column holding it. MySQL refuses the INSERT, from inside the
        // real channel, into the real table. The form caps a note at 500
        // characters, so nobody can reach this through the interface - which
        // is exactly why it is worth knowing that the decision survives it.
        $request = $this->fileCorrection();
        $log = Log::spy();

        $rejected = $this->corrections->reject($request, $this->first, str_repeat('م', 20000));

        $this->assertSame(RequestStatus::Rejected, $rejected->status);
        $this->assertDatabaseHas('attendance_corrections', [
            'id' => $request->getKey(),
            'status' => RequestStatus::Rejected->value,
        ]);

        // 40,000 bytes went into the request quite happily; 120,000 escaped
        // into the notification's payload did not.
        $this->assertSame(40000, strlen((string) $request->refresh()->decision_note));
        $this->assertSame(0, $this->bellCount($this->employee));

        $log->shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function the_second_administrator_to_answer_the_same_request_announces_nothing(): void
    {
        $request = $this->fileCorrection();

        $this->corrections->approve($request, $this->first, 'Agreed.');

        // The other administrator had the queue open and pressed Approve on
        // the row they were already looking at.
        try {
            $this->corrections->approve($request, $this->second, 'Agreed too.');
            $this->fail('The same request was approved twice.');
        } catch (AttendanceCorrectionRefusedException) {
            // The refusal is the expected outcome; the bell is the subject.
        }

        $this->assertSame(1, $this->bellCount($this->employee));
        $this->assertSame(
            'Agreed.',
            ((array) $this->employee->notifications()->firstOrFail()->data)['note'],
        );
    }

    #[Test]
    public function two_requests_filed_in_the_same_second_are_two_separate_lines(): void
    {
        $omar = $this->makeEmployee('omar@company.test');

        $this->fileCorrection();

        $this->leave->submit($omar, new LeaveDraft(
            type: LeaveType::Annual,
            startsOn: CarbonImmutable::parse('2026-09-20'),
            endsOn: CarbonImmutable::parse('2026-09-22'),
            isExitAndReturn: false,
            reason: 'A family matter.',
        ));

        foreach ([$this->first, $this->second] as $administrator) {
            $rows = $administrator->notifications()->get();

            $this->assertCount(2, $rows);
            $this->assertCount(2, $rows->pluck('id')->unique());
            $this->assertSame(
                ['correction', 'leave'],
                $rows->map(static fn (object $row): string => ((array) $row->data)['kind'])->sort()->values()->all(),
            );
        }

        // Both draw, and the clock they share does not merge or hide either.
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->first);

        Livewire::test(RequestNotices::class)
            ->assertOk()
            ->assertSee($this->employee->name)
            ->assertSee($omar->name);
    }

    #[Test]
    public function a_line_survives_the_account_it_is_about_being_deleted(): void
    {
        $request = $this->fileCorrection();
        $url = RequestNoticeLink::for(RequestNotice::about($request));

        $this->employee->delete();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->first);

        // The name was copied into the row when it was written, so the line
        // still reads as the sentence somebody wrote rather than as a blank.
        Livewire::test(RequestNotices::class)
            ->assertOk()
            ->assertSee($this->employee->name);

        // And following it lands on the queue rather than on an exception,
        // even though the request has left it with the account.
        $this->actingAs($this->first)->get($url)->assertOk();
    }

    #[Test]
    public function an_answer_waits_in_the_bell_of_an_account_that_is_deleted_and_restored(): void
    {
        $request = $this->fileCorrection();
        $this->corrections->approve($request, $this->first, 'Agreed.');

        $this->assertSame(1, $this->bellCount($this->employee));

        $this->employee->delete();

        // Nothing cascades: the row is not the account's to lose, and a
        // deleted account has no bell to open in any case.
        $this->assertSame(1, $this->bellCount($this->employee));

        $this->employee->restore();

        Filament::setCurrentPanel('employee');
        $this->actingAs($this->employee->fresh());

        Livewire::test(RequestNotices::class)
            ->assertOk()
            ->assertSee('Agreed.');
    }

    #[Test]
    public function following_a_line_about_a_request_somebody_else_already_answered_is_not_an_error(): void
    {
        $request = $this->fileCorrection();
        $url = RequestNoticeLink::for(RequestNotice::about($request));

        $this->corrections->approve($request, $this->first, 'Agreed.');

        // The queue opens filtered to what is pending, so the record this
        // link names is no longer in the table it is asking to open it from.
        // Filament finds nothing to mount and draws the queue; it does not
        // throw, and that is the whole assertion.
        $this->actingAs($this->second)->get($url)->assertOk();
    }

    #[Test]
    public function following_a_line_about_a_request_that_no_longer_exists_is_not_an_error(): void
    {
        $request = $this->fileCorrection(CarbonImmutable::parse('2026-09-08'));
        $url = RequestNoticeLink::for(RequestNotice::about($request));

        // Nothing in the interface deletes a request; this is the hand in the
        // database that the bell has to survive.
        AttendanceCorrection::query()->whereKey($request->getKey())->delete();

        $this->actingAs($this->first)->get($url)->assertOk();

        // The line itself is untouched by the row going away, and still draws.
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->first);

        Livewire::test(RequestNotices::class)
            ->assertOk()
            ->assertSee($this->employee->name);
    }
}
