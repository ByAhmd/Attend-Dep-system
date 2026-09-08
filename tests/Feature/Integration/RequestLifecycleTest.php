<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Enums\CorrectionReason;
use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Filament\Employee\Actions\RequestLeaveAction;
use App\Filament\Employee\Pages\Attendance as AttendancePage;
use App\Filament\Employee\Pages\Requests as RequestsPage;
use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveAttachmentStore;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The joins between the modules, driven end to end through the screens.
 *
 * Every module proved its own half: the workflow proved that approving a
 * correction moves a stored moment, and the admin table proved that a
 * corrected moment is marked. Neither could prove that a request an
 * employee actually filled in on a phone arrives at the administrator's
 * queue in a shape the administrator can decide, or that the word printed
 * beside the amended time is the same word on both panels. Those are the
 * assertions here, and each one crosses a boundary no single module owned.
 *
 * Nothing in this file constructs a row by hand where a screen could
 * produce it. A fixture is only used for the state a screen cannot reach -
 * a session recorded on an earlier day, or a second administrator.
 */
final class RequestLifecycleTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-09-15 09:00:00';

    private const string YESTERDAY = '2026-09-14';

    #[Test]
    public function a_correction_travels_from_the_employees_phone_to_the_stored_attendance_row(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);

        $employee = $this->makeEmployee('sara@company.test');
        $admin = $this->makeAdmin('admin@company.test');

        // What the device actually recorded: an early check-in the employee
        // says was wrong, on a day that is over.
        $session = $this->attendanceSession($employee, '09:40', '17:05', CarbonImmutable::parse(self::YESTERDAY));
        $deviceCheckIn = $session->check_in_at;

        // 1. The employee asks, through the modal on the attendance screen.
        $this->submitCorrection($employee, 'حضرت في الثامنة والربع ونسيت التسجيل.');

        $request = AttendanceCorrection::query()->sole();

        $this->assertSame(
            $session->id,
            $request->attendance_id,
            'The day picker chooses the only session of the day, and the choice has to reach the service.',
        );

        $this->assertSame($employee->id, $request->user_id, 'The request must be filed under the account that is signed in.');
        $this->assertSame(RequestStatus::Pending, $request->status);

        // 2. The administrator decides, through the queue.
        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('approve', $request, ['decision_note' => null])
            ->assertHasNoTableActionErrors();

        // 3. The attendance row now carries all three facts at once.
        $session->refresh();
        $request->refresh();

        $this->assertSame('08:15', $session->check_in_at->format('H:i'), 'The effective moment is the corrected one.');
        $this->assertNotNull($session->original_check_in_at);
        $this->assertSame(
            $deviceCheckIn->format('Y-m-d H:i'),
            $session->original_check_in_at->format('Y-m-d H:i'),
            'The archive must hold what the device wrote, not what was asked for.',
        );
        $this->assertSame($request->id, $session->check_in_correction_id);
        $this->assertSame(RequestStatus::Approved, $request->status);
        $this->assertSame($admin->id, $request->decided_by_id);

        // The check-out was never named, so nothing about it moved.
        $this->assertSame('17:05', $session->check_out_at?->format('H:i'));
        $this->assertNull($session->check_out_correction_id);
        $this->assertNull($session->original_check_out_at);

        // The device's own evidence is untouched: a correction moves a
        // clock, it does not claim the phone was somewhere else.
        $this->assertNotNull($session->check_in_latitude);
        $this->assertTrue($session->hasDeviceCheckIn());
    }

    #[Test]
    public function both_panels_print_the_same_word_beside_the_corrected_time(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);

        $employee = $this->makeEmployee('sara@company.test');
        $admin = $this->makeAdmin('admin@company.test');
        $this->attendanceSession($employee, '09:40', '17:05', CarbonImmutable::parse(self::YESTERDAY));

        $this->approveACorrectionOf($employee, $admin);

        // The admin list.
        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);

        Livewire::test(ListAttendances::class)
            ->assertSee(__('attendance.badges.corrected'))
            ->assertSee(__('attendance.badges.corrected_from', ['time' => '09:40']));

        // The employee's own history, which is a different table on a
        // different panel built by a different module.
        Filament::setCurrentPanel('employee');
        $this->actingAs($employee);

        Livewire::test(AttendanceHistoryWidget::class)
            ->assertSee(__('attendance.badges.corrected'))
            ->assertSee(__('attendance.badges.corrected_from', ['time' => '09:40']));

        // The point of the assertion: one key, so the two screens cannot
        // drift into calling the same fact two different things.
        $this->assertSame('مصحَّح', __('attendance.badges.corrected'));
    }

    #[Test]
    public function a_rejected_correction_carries_the_administrators_note_back_to_the_employee(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);

        $employee = $this->makeEmployee('sara@company.test');
        $admin = $this->makeAdmin('admin@company.test');
        $session = $this->attendanceSession($employee, '09:40', '17:05', CarbonImmutable::parse(self::YESTERDAY));

        $this->submitCorrection($employee);

        $request = AttendanceCorrection::query()->sole();
        $note = 'كشف الحضور يبيّن أنك دخلت المبنى في 09:38.';

        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('reject', $request, ['decision_note' => $note])
            ->assertHasNoTableActionErrors();

        $session->refresh();

        $this->assertSame('09:40', $session->check_in_at->format('H:i'), 'A rejection writes nothing to the attendance row.');
        $this->assertNull($session->check_in_correction_id);

        // The employee reads the answer where they asked the question.
        Filament::setCurrentPanel('employee');
        $this->actingAs($employee);

        Livewire::test(RequestsPage::class)
            ->assertOk()
            ->assertSee(RequestStatus::Rejected->label())
            ->assertSee($note);
    }

    #[Test]
    public function an_exhausted_allowance_is_announced_before_the_employee_types_anything(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(1);

        $employee = $this->makeEmployee('sara@company.test');
        $this->attendanceSession($employee, '09:40', '17:05', CarbonImmutable::parse(self::YESTERDAY));

        $this->submitCorrection($employee);

        // The one allowance is now spent. Opening the modal a second time
        // must say so on arrival - not accept a typed request and refuse it
        // at the end, which is the failure this assertion exists to catch.
        $page = Livewire::test(AttendancePage::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->assertMountedActionModalSee(__('corrections.employee.quota_spent_heading'))
            ->assertMountedActionModalDontSee(__('corrections.fields.attendance_date'));

        $this->assertNull(
            $page->instance()->getMountedAction()?->getModalSubmitAction(),
            'A modal with nothing to send must not offer a button that invites trying.',
        );

        $this->assertSame(1, AttendanceCorrection::query()->count());
    }

    #[Test]
    public function an_administrator_cannot_decide_their_own_request_on_either_queue(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);

        $admin = $this->makeAdmin('admin@company.test');
        $second = $this->makeAdmin('second@company.test');

        // The administrator files both kinds of request as themselves.
        $ownCorrection = $this->correctionRequest($admin, on: CarbonImmutable::parse(self::YESTERDAY));
        $ownLeave = $this->leaveRequest($admin);

        $othersCorrection = $this->correctionRequest($this->makeEmployee('sara@company.test'));
        $othersLeave = $this->leaveRequest($this->makeEmployee('omar@company.test'));

        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);

        Livewire::test(ListAttendanceCorrections::class)
            ->assertTableActionHidden('approve', $ownCorrection)
            ->assertTableActionHidden('reject', $ownCorrection)
            ->assertTableActionVisible('approve', $othersCorrection);

        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionHidden('approve', $ownLeave)
            ->assertTableActionHidden('reject', $ownLeave)
            ->assertTableActionVisible('approve', $othersLeave);

        // The gate, not only the button: a payload that names the hidden
        // action is refused by the same policy that hid it.
        $this->assertFalse($admin->can('decide', $ownCorrection));
        $this->assertFalse($admin->can('decide', $ownLeave));

        // And it is genuinely about the requester, not about the queue:
        // another administrator may decide exactly these two.
        $this->assertTrue($second->can('decide', $ownCorrection));
        $this->assertTrue($second->can('decide', $ownLeave));
    }

    #[Test]
    public function an_employee_never_reaches_a_decision_control_on_their_own_request(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee('sara@company.test');
        $correction = $this->correctionRequest($employee, on: CarbonImmutable::parse(self::YESTERDAY));
        $leave = $this->leaveRequest($employee);

        $this->assertFalse($employee->can('decide', $correction));
        $this->assertFalse($employee->can('decide', $leave));

        // The queues are not merely empty for them - the panel is shut.
        Filament::setCurrentPanel('admin');
        $this->actingAs($employee);

        $this->get('/admin/attendance-corrections')->assertForbidden();
        $this->get('/admin/leave-requests')->assertForbidden();
    }

    /*
     | The leave attachment, across all three modules that touch it: the form
     | that uploads (M6), the disk and the download (M7), and the policy the
     | admin resource asks (M2). These run on the real clock: Livewire sweeps
     | a temporary upload it judges older than a day, so a frozen clock far
     | from the machine's date makes a fresh upload look ancient.
     */

    #[Test]
    public function the_employee_who_attached_a_document_is_the_one_who_can_fetch_it(): void
    {
        Storage::fake(LeaveAttachmentStore::DISK);

        $employee = $this->makeEmployee('sara@company.test');
        $stranger = $this->makeEmployee('omar@company.test');
        $admin = $this->makeAdmin('admin@company.test');

        $request = $this->attachADocument($employee);
        $path = (string) $request->attachment_path;

        Storage::disk(LeaveAttachmentStore::DISK)->assertExists($path);

        // The owner.
        $this->actingAs($employee)
            ->get(route('leave-attachments.show', $request))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Another employee, who has no business with it at all.
        $this->actingAs($stranger)
            ->get(route('leave-attachments.show', $request))
            ->assertForbidden();

        // The administrator who has to decide the request.
        $this->actingAs($admin)
            ->get(route('leave-attachments.show', $request))
            ->assertOk();

        // A stale link, once the document is taken away, is a 404 and not a
        // download of nothing.
        app(LeaveAttachmentStore::class)->discard($request);

        Storage::disk(LeaveAttachmentStore::DISK)->assertMissing($path);

        $request->refresh();

        $this->assertNull($request->attachment_path);
        $this->assertNull($request->attachment_name);
        $this->assertNull($request->attachment_size);
        $this->assertNull($request->attachment_mime_type);

        $this->actingAs($employee)
            ->get(route('leave-attachments.show', $request))
            ->assertNotFound();
    }

    #[Test]
    public function a_refused_leave_request_leaves_no_document_behind(): void
    {
        Storage::fake(LeaveAttachmentStore::DISK);

        $employee = $this->makeEmployee('sara@company.test');

        // One pending request already stands over these days, so the second
        // is refused by the workflow after the file is already on the disk.
        $first = $this->attachADocument($employee);

        Filament::setCurrentPanel('employee');
        $this->actingAs($employee);

        Livewire::test(AttendancePage::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Sick->value,
                'starts_on' => CarbonImmutable::now()->addDay()->toDateString(),
                'ends_on' => CarbonImmutable::now()->addDays(2)->toDateString(),
                'reason' => 'طلب ثانٍ يتداخل مع الأول.',
                'attachment_path' => [UploadedFile::fake()->createWithContent('second.pdf', str_repeat('1', 512))],
            ]);

        $this->assertSame(1, LeaveRequest::query()->count(), 'The overlapping request must not have been written.');

        // Exactly one file: the accepted request's. The refused upload was
        // swept by the action that handled the refusal.
        $this->assertSame(
            [(string) $first->attachment_path],
            Storage::disk(LeaveAttachmentStore::DISK)->allFiles(),
        );
    }

    #[Test]
    public function the_error_page_an_employee_lands_on_reads_in_their_own_language(): void
    {
        Storage::fake(LeaveAttachmentStore::DISK);

        $employee = $this->makeEmployee('sara@company.test');
        $stranger = $this->makeEmployee('omar@company.test');

        $request = $this->attachADocument($employee);

        // Arabic is the shipped default, so this is what the live employee
        // sees. The framework's own page is English, left to right, and
        // says "This action is unauthorized."
        $response = $this->actingAs($stranger)
            ->get(route('leave-attachments.show', $request))
            ->assertForbidden();

        $response->assertSee(__('errors.codes.403.title'));
        $response->assertDontSee('This action is unauthorized', false);
        $this->assertStringContainsString('dir="rtl"', $response->getContent() ?: '');
    }

    /**
     * Files a leave request through the employee's own form, with a document
     * attached, and returns the row it wrote.
     */
    private function attachADocument(User $employee): LeaveRequest
    {
        Filament::setCurrentPanel('employee');
        $this->actingAs($employee);

        Livewire::test(AttendancePage::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Sick->value,
                'starts_on' => CarbonImmutable::now()->addDay()->toDateString(),
                'ends_on' => CarbonImmutable::now()->addDays(2)->toDateString(),
                'reason' => 'تقرير طبي مرفق مع الطلب.',
                'attachment_path' => [UploadedFile::fake()->createWithContent('تقرير طبي.pdf', str_repeat('0', 1024))],
            ])
            ->assertHasNoActionErrors();

        $request = LeaveRequest::query()->latest('id')->firstOrFail();

        $this->assertNotNull($request->attachment_path, 'The upload must have reached the disk.');

        return $request;
    }

    /**
     * Asks for a check-in of 08:15 on yesterday, through the modal, one
     * field at a time.
     *
     * Field by field rather than in one payload because that is what a
     * browser does, and because the day picker's own wiring - which chooses
     * the session when the day has exactly one - only runs when the day is
     * set on its own.
     */
    private function submitCorrection(User $employee, ?string $note = null): void
    {
        Filament::setCurrentPanel('employee');
        $this->actingAs($employee);

        $modal = Livewire::test(AttendancePage::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->set('mountedActions.0.data.attendance_date', self::YESTERDAY)
            ->set('mountedActions.0.data.reason', CorrectionReason::ForgotToRecord->value)
            ->set('mountedActions.0.data.requested_check_in_at', '08:15');

        if ($note !== null) {
            $modal->set('mountedActions.0.data.note', $note);
        }

        $modal->callMountedAction()->assertHasNoActionErrors();
    }

    /**
     * Submits and approves a check-in correction, through both screens.
     */
    private function approveACorrectionOf(User $employee, User $admin): void
    {
        $this->submitCorrection($employee);

        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('approve', AttendanceCorrection::query()->sole(), ['decision_note' => null])
            ->assertHasNoTableActionErrors();
    }
}
