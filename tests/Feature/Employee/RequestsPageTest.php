<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Filament\Employee\Actions\RequestLeaveAction;
use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\Requests;
use App\Filament\Employee\Widgets\MyCorrectionRequestsWidget;
use App\Filament\Employee\Widgets\MyLeaveRequestsWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * /requests: the screen that tells an employee what became of what they
 * asked for.
 *
 * This page is the notification mechanism - there is no email and no bell -
 * so the two things worth proving hardest are that it shows the reader their
 * own requests and nobody else's, and that a rejection arrives with the note
 * that explains it.
 */
final class RequestsPageTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-09-15 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
    }

    #[Test]
    public function the_page_renders_for_an_active_employee(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Requests::class)
            ->assertOk()
            ->assertSee(__('requests.page.title'))
            ->assertSee(__('requests.page.subheading'));
    }

    #[Test]
    public function the_route_answers_over_http(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        $this->get('/requests')->assertOk();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/requests')->assertRedirect('/login');
    }

    /**
     * The band is the only way back: this panel has no navigation at all,
     * and a dead end on a phone is a screen somebody leaves by closing the
     * tab.
     */
    #[Test]
    public function the_page_offers_a_way_back_to_the_attendance_screen_and_both_forms(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Requests::class)
            ->assertSee(__('requests.tiles.attendance'))
            ->assertSee(__('requests.tiles.caption_attendance'))
            ->assertSee(__('requests.tiles.correction'))
            ->assertSee(__('requests.tiles.leave'))
            ->assertSeeHtml(Attendance::getUrl())
            ->assertActionVisible(RequestCorrectionAction::NAME)
            ->assertActionVisible(RequestLeaveAction::NAME)
            ->assertOk();
    }

    #[Test]
    public function the_correction_table_shows_the_signed_in_employees_rows_and_no_others(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();
        $somebodyElse = $this->makeEmployee('other@example.test');

        $mine = $this->correctionRequest($employee, on: CarbonImmutable::parse('2026-09-10'));
        $theirs = $this->correctionRequest($somebodyElse, on: CarbonImmutable::parse('2026-09-10'));

        Livewire::test(MyCorrectionRequestsWidget::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertOk();
    }

    #[Test]
    public function the_leave_table_shows_the_signed_in_employees_rows_and_no_others(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();
        $somebodyElse = $this->makeEmployee('other@example.test');

        $mine = $this->leaveRequest($employee, CarbonImmutable::parse('2026-09-20'));
        $theirs = $this->leaveRequest($somebodyElse, CarbonImmutable::parse('2026-09-20'));

        Livewire::test(MyLeaveRequestsWidget::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertOk();
    }

    /**
     * An employee who was told no is owed the reason on the same screen as
     * the word "no": the note is a column here, not a detail behind a click.
     */
    #[Test]
    public function a_rejected_request_carries_the_administrators_note(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();
        $admin = $this->makeAdmin('admin@example.test');

        $correction = $this->correctionRequest($employee, on: CarbonImmutable::parse('2026-09-10'));
        $correction->forceFill([
            'status' => RequestStatus::Rejected,
            'decided_by_id' => $admin->id,
            'decided_at' => CarbonImmutable::parse(self::FROZEN_NOW),
            'decision_note' => 'السجل الحالي مطابق لما رأته خدمة الموقع.',
        ])->save();

        $leave = $this->leaveRequest($employee, CarbonImmutable::parse('2026-09-20'));
        $leave->forceFill([
            'status' => RequestStatus::Rejected,
            'decided_by_id' => $admin->id,
            'decided_at' => CarbonImmutable::parse(self::FROZEN_NOW),
            'decision_note' => 'الفريق ناقص في هذا الأسبوع.',
        ])->save();

        Livewire::test(MyCorrectionRequestsWidget::class)
            ->assertOk()
            ->assertSee(RequestStatus::Rejected->label())
            ->assertSee('السجل الحالي مطابق لما رأته خدمة الموقع.');

        Livewire::test(MyLeaveRequestsWidget::class)
            ->assertOk()
            ->assertSee(RequestStatus::Rejected->label())
            ->assertSee('الفريق ناقص في هذا الأسبوع.');
    }

    #[Test]
    public function a_pending_request_reads_as_under_review(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();

        $this->correctionRequest($employee, on: CarbonImmutable::parse('2026-09-10'));

        Livewire::test(MyCorrectionRequestsWidget::class)
            ->assertOk()
            ->assertSee(RequestStatus::Pending->label());
    }

    #[Test]
    public function both_tables_say_what_to_do_rather_than_what_is_missing(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(MyCorrectionRequestsWidget::class)
            ->assertOk()
            ->assertSee(__('corrections.employee.empty_heading'))
            ->assertSee(__('corrections.employee.empty_description'));

        Livewire::test(MyLeaveRequestsWidget::class)
            ->assertOk()
            ->assertSee(__('leave.employee.empty_heading'))
            ->assertSee(__('leave.employee.empty_description'));
    }

    #[Test]
    public function a_leave_request_with_no_document_says_so_rather_than_offering_a_link(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();

        $this->leaveRequest($employee, CarbonImmutable::parse('2026-09-20'), type: LeaveType::Sick);

        Livewire::test(MyLeaveRequestsWidget::class)
            ->assertOk()
            ->assertSee(__('leave.placeholders.no_attachment'));
    }

    #[Test]
    public function an_employee_deactivated_after_signing_in_is_signed_out_of_this_page(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();

        $this->get('/requests')->assertOk();

        $employee->forceFill(['status' => UserStatus::Inactive])->save();

        $this->get('/requests')->assertRedirect('/login');

        $this->assertGuest();
    }

    #[Test]
    public function an_administrator_reaches_this_page_like_any_other_account(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->actingAs($this->makeAdmin('admin@example.test'));

        $this->get('/requests')->assertOk();
    }

    private function signIn(): User
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        return $employee;
    }
}
