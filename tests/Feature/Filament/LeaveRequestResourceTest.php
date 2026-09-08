<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * LeaveRequestResource: the second queue, answered by the same person.
 *
 * The same shape as the correction queue, verb for verb, and proved the
 * same way. What is different is what an approval means: it records what
 * was agreed and changes no attendance row, so this test also holds the
 * line the interface must not blur - attendance recorded inside the range
 * is a warning an approver reads and may overrule, while leave already
 * agreed over the same days is refused by the workflow.
 */
final class LeaveRequestResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->freezeRiyadhClock('2026-09-08 18:00');

        $this->admin = $this->makeAdmin('admin@company.test');

        $this->actingAs($this->admin);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-08', 'Asia/Riyadh');
    }

    #[Test]
    public function the_queue_opens_on_what_is_waiting_with_the_longest_wait_first(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');
        $lina = $this->makeEmployee('lina@company.test');

        $this->freezeRiyadhClock('2026-09-08 09:00');
        $first = $this->leaveRequest($sara);

        $this->freezeRiyadhClock('2026-09-08 11:00');
        $second = $this->leaveRequest($omar);

        $this->freezeRiyadhClock('2026-09-08 18:00');
        $decided = LeaveRequest::factory()->for($lina)->approvedBy($this->admin)->create();

        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->assertCanNotSeeTableRecords([$decided])
            ->assertOk();
    }

    #[Test]
    public function a_row_names_the_employee_the_type_the_period_and_the_number_of_days(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara, $this->today(), $this->today()->addDays(2));

        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee($sara->name)
            ->assertSee(LeaveType::Annual->label())
            ->assertSee('2026-09-08 → 2026-09-10')
            ->assertSee(trans_choice('leave.units.days', 3));
    }

    #[Test]
    public function the_total_adds_up_the_days_of_whatever_is_selected(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        // Three days and two days, both ends inclusive.
        $this->leaveRequest($sara, $this->today(), $this->today()->addDays(2));
        $this->leaveRequest($omar, $this->today(), $this->today()->addDay());

        Livewire::test(ListLeaveRequests::class)
            ->assertTableColumnSummarySet('starts_on', 'total_days', trans_choice('leave.units.days', 5))
            ->filterTable('user_id', $sara->id)
            ->assertTableColumnSummarySet('starts_on', 'total_days', trans_choice('leave.units.days', 3));
    }

    #[Test]
    public function clearing_the_status_filter_reveals_the_requests_already_decided(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $pending = $this->leaveRequest($sara);
        $rejected = LeaveRequest::factory()->for($omar)->rejectedBy($this->admin, 'الفريق ناقص هذا الأسبوع.')->create();

        Livewire::test(ListLeaveRequests::class)
            ->assertCanNotSeeTableRecords([$rejected])
            ->filterTable('status', null)
            ->assertCanSeeTableRecords([$pending, $rejected]);
    }

    #[Test]
    public function the_type_filter_narrows_the_queue(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $annual = $this->leaveRequest($sara);
        $sick = $this->leaveRequest($omar, type: LeaveType::Sick);

        Livewire::test(ListLeaveRequests::class)
            ->filterTable('type', LeaveType::Sick->value)
            ->assertCanSeeTableRecords([$sick])
            ->assertCanNotSeeTableRecords([$annual]);
    }

    #[Test]
    public function the_period_filter_finds_a_leave_that_began_before_the_window(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');
        $lina = $this->makeEmployee('lina@company.test');

        // A fortnight that started a week ago and runs into the window: the
        // one a filter comparing start dates alone would hide, and exactly
        // the absence somebody scanning a week needs to see.
        $spanning = $this->leaveRequest($sara, $this->today()->subDays(7), $this->today()->addDays(7));
        $inside = $this->leaveRequest($omar, $this->today(), $this->today()->addDay());
        $after = $this->leaveRequest($lina, $this->today()->addDays(20), $this->today()->addDays(21));

        Livewire::test(ListLeaveRequests::class)
            ->filterTable('period', [
                'from' => $this->today()->toDateString(),
                'until' => $this->today()->addDays(2)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$spanning, $inside])
            ->assertCanNotSeeTableRecords([$after]);
    }

    #[Test]
    public function the_on_leave_today_filter_reports_only_what_was_actually_agreed(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');
        $lina = $this->makeEmployee('lina@company.test');

        $approved = LeaveRequest::factory()
            ->for($sara)
            ->between($this->today()->subDay(), $this->today()->addDay())
            ->approvedBy($this->admin)
            ->create();

        // Still a question, so it is not an answer to "who is off today".
        $pending = $this->leaveRequest($omar, $this->today(), $this->today());

        $elsewhere = LeaveRequest::factory()
            ->for($lina)
            ->between($this->today()->addDays(10), $this->today()->addDays(12))
            ->approvedBy($this->admin)
            ->create();

        Livewire::test(ListLeaveRequests::class)
            // The filter states a fact, so it says nothing while the queue
            // is still filtered to the questions.
            ->filterTable('status', null)
            ->filterTable('on_leave_today')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending, $elsewhere]);
    }

    #[Test]
    public function a_request_from_a_deleted_account_is_out_of_the_queue_until_the_filter_is_cleared(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $gone = $this->makeEmployee('gone@company.test');

        $hers = $this->leaveRequest($sara);
        $theirs = $this->leaveRequest($gone);

        $gone->delete();

        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$hers])
            ->assertCanNotSeeTableRecords([$theirs])
            ->filterTable('deleted_employee', null)
            ->assertCanSeeTableRecords([$hers, $theirs])
            ->assertTableActionHidden('approve', $theirs);
    }

    #[Test]
    public function the_record_modal_reports_attendance_inside_the_range_as_a_warning(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara, $this->today(), $this->today()->addDays(2));

        // Half a day worked before going home: ordinary, and never a reason
        // to refuse the leave.
        $this->attendanceSession($sara, '08:00', '12:00');

        Livewire::test(ListLeaveRequests::class)
            ->mountTableAction('view', $request)
            ->assertMountedActionModalSee([
                __('leave.sections.conflicts'),
                __('leave.fields.attended_days'),
                trans_choice('leave.units.days', 1),
                __('leave.placeholders.no_overlap'),
            ]);

        // A warning and not a refusal: the decision is still offered.
        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionVisible('approve', $request);
    }

    #[Test]
    public function the_record_modal_names_the_other_leave_that_covers_the_same_days(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        LeaveRequest::factory()
            ->for($sara)
            ->between($this->today()->addDay(), $this->today()->addDays(4))
            ->approvedBy($this->admin)
            ->create();

        $request = $this->leaveRequest($sara, $this->today(), $this->today()->addDays(2));

        Livewire::test(ListLeaveRequests::class)
            ->mountTableAction('view', $request)
            ->assertMountedActionModalSee(
                $this->today()->addDay()->toDateString().' → '.$this->today()->addDays(4)->toDateString(),
            );
    }

    #[Test]
    public function a_request_with_no_document_says_so_rather_than_printing_a_blank(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara);

        Livewire::test(ListLeaveRequests::class)
            ->mountTableAction('view', $request)
            ->assertMountedActionModalSee([
                __('leave.fields.attachment'),
                __('leave.placeholders.no_attachment'),
            ]);
    }

    #[Test]
    public function a_supporting_document_is_reached_through_its_own_route_and_never_by_path(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara);

        $request->forceFill([
            'attachment_path' => 'leave-requests/'.$request->id.'/report.pdf',
            'attachment_name' => 'report.pdf',
            'attachment_size' => 2048,
            'attachment_mime_type' => 'application/pdf',
        ])->save();

        Livewire::test(ListLeaveRequests::class)
            ->mountTableAction('view', $request)
            ->assertMountedActionModalSee('report.pdf')
            // The link is the named route, which asks this request's policy
            // before it opens the private disk. The stored path is never on
            // the page at all.
            ->assertMountedActionModalSee(route('leave-attachments.show', $request))
            ->assertMountedActionModalDontSee($request->attachment_path);
    }

    #[Test]
    public function approving_leave_records_the_agreement_and_creates_no_attendance(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara, $this->today(), $this->today()->addDays(2));

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('approve', $request, data: ['decision_note' => 'موافق، رحلة موفقة.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('leave.notifications.approved', ['name' => $sara->name]));

        $request->refresh();

        $this->assertSame(RequestStatus::Approved, $request->status);
        $this->assertSame($this->admin->id, $request->decided_by_id);
        $this->assertSame('موافق، رحلة موفقة.', $request->decision_note);
        $this->assertSame(0, Attendance::query()->count());
    }

    #[Test]
    public function rejecting_leave_records_the_note_the_employee_will_read(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara);

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('reject', $request, data: ['decision_note' => 'الفريق ناقص في هذا الأسبوع.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('leave.notifications.rejected', ['name' => $sara->name]));

        $request->refresh();

        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertSame('الفريق ناقص في هذا الأسبوع.', $request->decision_note);
    }

    #[Test]
    public function a_rejection_cannot_be_sent_without_a_reason(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->leaveRequest($sara);

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('reject', $request, data: ['decision_note' => ''])
            ->assertHasTableActionErrors(['decision_note' => 'required']);

        $this->assertSame(RequestStatus::Pending, $request->refresh()->status);
    }

    #[Test]
    public function leave_the_service_refuses_leaves_the_request_pending(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        // Already agreed for the same week. The workflow re-asks the overlap
        // question under a lock at the moment of the decision, because two
        // requests that did not overlap at submission can be approved into
        // an overlap if the queue is worked in the wrong order.
        LeaveRequest::factory()
            ->for($sara)
            ->between($this->today(), $this->today()->addDays(4))
            ->approvedBy($this->admin)
            ->create();

        $request = $this->leaveRequest($sara, $this->today()->addDays(2), $this->today()->addDays(6));

        Livewire::test(ListLeaveRequests::class)
            ->callTableAction('approve', $request)
            ->assertActionHalted(TestAction::make('approve')->table($request))
            ->assertNotified(__('leave.notifications.not_applied'));

        $request->refresh();

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertNull($request->decided_by_id);
        $this->assertNull($request->decided_at);
    }

    #[Test]
    public function a_decided_request_is_never_re_decided(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $approved = LeaveRequest::factory()->for($sara)->approvedBy($this->admin)->create();

        Livewire::test(ListLeaveRequests::class)
            ->filterTable('status', null)
            ->assertTableActionVisible('view', $approved)
            ->assertTableActionHidden('approve', $approved)
            ->assertTableActionHidden('reject', $approved);
    }

    #[Test]
    public function nobody_decides_their_own_request(): void
    {
        $own = $this->leaveRequest($this->admin);

        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$own])
            ->assertTableActionHidden('approve', $own)
            ->assertTableActionHidden('reject', $own);
    }

    #[Test]
    public function the_queue_is_read_and_decided_and_never_written_to(): void
    {
        $this->assertFalse(LeaveRequestResource::canCreate());
        $this->assertSame(['index'], array_keys(LeaveRequestResource::getPages()));

        $this->get('/admin/leave-requests')->assertOk();
        $this->get('/admin/leave-requests/create')->assertNotFound();
    }

    #[Test]
    public function the_queue_has_no_edit_delete_or_bulk_action(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $this->leaveRequest($sara);

        Livewire::test(ListLeaveRequests::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    #[Test]
    public function an_employee_cannot_reach_the_queue(): void
    {
        $this->actingAs($this->makeEmployee('sara@company.test'))
            ->get('/admin/leave-requests')
            ->assertForbidden();
    }
}
