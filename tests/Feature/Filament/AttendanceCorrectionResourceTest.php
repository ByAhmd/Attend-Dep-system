<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
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
 * AttendanceCorrectionResource: the queue an administrator answers.
 *
 * Two things are being proved here. The first is that the queue behaves
 * like a queue: it opens on what is waiting, oldest first, and every filter
 * narrows it without changing what a row says. The second is the one that
 * matters - approving a correction is the only act in this system that
 * changes an attendance row, so the screen must show the decision's whole
 * basis, must refuse to offer a decision nobody is allowed to take, and
 * must leave the request untouched when the service refuses to carry it
 * out.
 */
final class AttendanceCorrectionResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        // A fixed Riyadh evening, so every wall-clock time a request asks
        // for is already in the past when it is approved and the future
        // guard is never what a test is accidentally exercising.
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
        $first = $this->correctionRequest($sara);

        $this->freezeRiyadhClock('2026-09-08 11:00');
        $second = $this->correctionRequest($omar);

        $this->freezeRiyadhClock('2026-09-08 18:00');
        $decided = AttendanceCorrection::factory()->for($lina)->approvedBy($this->admin)->create();

        Livewire::test(ListAttendanceCorrections::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->assertCanNotSeeTableRecords([$decided])
            ->assertSee($sara->name)
            ->assertSee($omar->name)
            ->assertOk();
    }

    #[Test]
    public function a_row_names_the_employee_the_reason_the_day_and_the_times_asked_for(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->correctionRequest($sara, checkIn: '08:00', checkOut: '17:00');

        Livewire::test(ListAttendanceCorrections::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee($sara->name)
            ->assertSee(CorrectionReason::ForgotToRecord->label())
            ->assertSee($this->today()->format('Y-m-d'))
            ->assertSee('08:00 → 17:00');
    }

    #[Test]
    public function clearing_the_status_filter_reveals_the_requests_already_decided(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $pending = $this->correctionRequest($sara);
        $rejected = AttendanceCorrection::factory()->for($omar)->rejectedBy($this->admin, 'لا يوجد ما يؤكد ذلك.')->create();

        Livewire::test(ListAttendanceCorrections::class)
            ->assertCanNotSeeTableRecords([$rejected])
            ->filterTable('status', null)
            ->assertCanSeeTableRecords([$pending, $rejected])
            ->filterTable('status', RequestStatus::Rejected->value)
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    #[Test]
    public function the_employee_filter_narrows_the_queue_to_one_person(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $hers = $this->correctionRequest($sara);
        $his = $this->correctionRequest($omar);

        Livewire::test(ListAttendanceCorrections::class)
            ->filterTable('user_id', $sara->id)
            ->assertCanSeeTableRecords([$hers])
            ->assertCanNotSeeTableRecords([$his]);
    }

    #[Test]
    public function the_reason_filter_narrows_the_queue(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $forgot = $this->correctionRequest($sara);
        $remote = AttendanceCorrection::factory()
            ->for($omar)
            ->create(['reason' => CorrectionReason::RemoteWork]);

        Livewire::test(ListAttendanceCorrections::class)
            ->filterTable('reason', CorrectionReason::RemoteWork->value)
            ->assertCanSeeTableRecords([$remote])
            ->assertCanNotSeeTableRecords([$forgot]);
    }

    #[Test]
    public function the_day_filter_keeps_the_requests_about_the_period_it_names(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');
        $lina = $this->makeEmployee('lina@company.test');

        $onFrom = $this->correctionRequest($sara, on: $this->today()->subDays(3));
        $onUntil = $this->correctionRequest($omar, on: $this->today()->subDay());
        $tooOld = $this->correctionRequest($lina, on: $this->today()->subDays(6));

        Livewire::test(ListAttendanceCorrections::class)
            ->filterTable('date_range', [
                'from' => $this->today()->subDays(3)->toDateString(),
                'until' => $this->today()->subDay()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$onFrom, $onUntil])
            ->assertCanNotSeeTableRecords([$tooOld]);
    }

    #[Test]
    public function a_request_from_a_deleted_account_is_out_of_the_queue_until_the_filter_is_cleared(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $gone = $this->makeEmployee('gone@company.test');

        $hers = $this->correctionRequest($sara);
        $theirs = $this->correctionRequest($gone);

        $gone->delete();

        Livewire::test(ListAttendanceCorrections::class)
            ->assertCanSeeTableRecords([$hers])
            ->assertCanNotSeeTableRecords([$theirs])
            ->filterTable('deleted_employee', null)
            ->assertCanSeeTableRecords([$hers, $theirs])
            ->filterTable('deleted_employee', true)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$hers]);
    }

    #[Test]
    public function the_record_modal_shows_what_the_device_recorded_beside_what_is_asked_for(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $session = $this->attendanceSession($sara, '09:15', '17:00');
        $request = $this->correctionRequest($sara, $session, checkIn: '08:00');

        Livewire::test(ListAttendanceCorrections::class)
            ->mountTableAction('view', $request)
            ->assertMountedActionModalSee([
                __('corrections.sections.recorded'),
                __('corrections.sections.requested'),
                // What the device wrote, and what is being asked instead.
                '09:15',
                '08:00',
                // The distance is on the device's side of the comparison
                // and nowhere near the other: it is what made the recorded
                // moment evidence rather than a claim.
                __('corrections.fields.recorded_distance'),
                CorrectionReason::ForgotToRecord->label(),
            ]);
    }

    #[Test]
    public function a_day_the_device_recorded_nothing_about_says_so_rather_than_printing_a_blank(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->correctionRequest($sara);

        Livewire::test(ListAttendanceCorrections::class)
            ->mountTableAction('view', $request)
            ->assertMountedActionModalSee(__('corrections.placeholders.no_device_record'));
    }

    #[Test]
    public function approving_a_correction_amends_the_day_and_keeps_what_the_device_recorded(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $session = $this->attendanceSession($sara, '09:15', '17:00');
        $request = $this->correctionRequest($sara, $session, checkIn: '08:00');

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('approve', $request, data: ['decision_note' => 'أكّد المدير الحضور.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('corrections.notifications.approved', ['name' => $sara->name]));

        $session->refresh();
        $request->refresh();

        $this->assertSame('08:00', $session->check_in_at->format('H:i'));
        $this->assertSame('09:15', $session->original_check_in_at?->format('H:i'));
        $this->assertSame($request->id, $session->check_in_correction_id);
        $this->assertSame(RequestStatus::Approved, $request->status);
        $this->assertSame($this->admin->id, $request->decided_by_id);
        $this->assertSame('أكّد المدير الحضور.', $request->decision_note);
    }

    #[Test]
    public function rejecting_a_correction_records_the_note_and_changes_no_attendance(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $session = $this->attendanceSession($sara, '09:15', '17:00');
        $request = $this->correctionRequest($sara, $session, checkIn: '08:00');

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('reject', $request, data: ['decision_note' => 'لا يوجد ما يؤكد الحضور في ذلك الوقت.'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('corrections.notifications.rejected', ['name' => $sara->name]));

        $session->refresh();
        $request->refresh();

        $this->assertSame('09:15', $session->check_in_at->format('H:i'));
        $this->assertNull($session->check_in_correction_id);
        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertSame('لا يوجد ما يؤكد الحضور في ذلك الوقت.', $request->decision_note);
    }

    #[Test]
    public function a_rejection_cannot_be_sent_without_a_reason(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $request = $this->correctionRequest($sara);

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('reject', $request, data: ['decision_note' => ''])
            ->assertHasTableActionErrors(['decision_note' => 'required']);

        $this->assertSame(RequestStatus::Pending, $request->refresh()->status);
    }

    #[Test]
    public function a_correction_the_service_refuses_leaves_the_request_pending_and_writes_nothing(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $yesterday = $this->today()->subDay();

        // An untouched session in the middle of the day, and a request that
        // would lay a whole working day on top of it. The service refuses
        // it under a lock; the unique index never sees it.
        $existing = $this->attendanceSession($sara, '12:00', '13:00', $yesterday);
        $request = $this->correctionRequest($sara, on: $yesterday);

        Livewire::test(ListAttendanceCorrections::class)
            ->callTableAction('approve', $request)
            // The modal is still open with whatever was typed in it: a
            // decision the system could not carry out is not a decision.
            ->assertActionHalted(TestAction::make('approve')->table($request))
            ->assertNotified(__('corrections.notifications.not_applied'));

        $request->refresh();
        $existing->refresh();

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertNull($request->decided_by_id);
        $this->assertNull($request->decided_at);
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame('12:00', $existing->check_in_at->format('H:i'));
    }

    #[Test]
    public function a_decided_request_is_never_re_decided(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $approved = AttendanceCorrection::factory()->for($sara)->approvedBy($this->admin)->create();

        Livewire::test(ListAttendanceCorrections::class)
            ->filterTable('status', null)
            ->assertTableActionVisible('view', $approved)
            ->assertTableActionHidden('approve', $approved)
            ->assertTableActionHidden('reject', $approved);
    }

    #[Test]
    public function nobody_decides_their_own_request_and_the_screen_says_why(): void
    {
        $own = $this->correctionRequest($this->admin);

        Livewire::test(ListAttendanceCorrections::class)
            ->assertCanSeeTableRecords([$own])
            ->assertTableActionHidden('approve', $own)
            ->assertTableActionHidden('reject', $own)
            ->mountTableAction('view', $own)
            ->assertMountedActionModalSee(__('corrections.helpers.own_request'));
    }

    #[Test]
    public function a_request_from_a_deleted_account_offers_no_decision(): void
    {
        $gone = $this->makeEmployee('gone@company.test');

        $request = $this->correctionRequest($gone);

        $gone->delete();

        Livewire::test(ListAttendanceCorrections::class)
            ->filterTable('deleted_employee', null)
            ->assertCanSeeTableRecords([$request])
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('reject', $request);
    }

    #[Test]
    public function the_queue_is_read_and_decided_and_never_written_to(): void
    {
        $this->assertFalse(AttendanceCorrectionResource::canCreate());
        $this->assertSame(['index'], array_keys(AttendanceCorrectionResource::getPages()));

        $this->get('/admin/attendance-corrections')->assertOk();
        $this->get('/admin/attendance-corrections/create')->assertNotFound();
    }

    #[Test]
    public function the_queue_has_no_edit_delete_or_bulk_action(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $this->correctionRequest($sara);

        Livewire::test(ListAttendanceCorrections::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    #[Test]
    public function an_employee_cannot_reach_the_queue(): void
    {
        $this->actingAs($this->makeEmployee('sara@company.test'))
            ->get('/admin/attendance-corrections')
            ->assertForbidden();
    }
}
