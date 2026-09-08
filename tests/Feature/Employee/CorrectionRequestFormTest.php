<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Filament\Employee\Pages\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The correction form as an employee meets it: a tile that is always there,
 * a modal that says what the day already holds, and a service that refuses
 * the same things whether or not the form was ever rendered.
 *
 * Every payload here is sent the way the browser sends one - through the
 * mounted action's own state - including the payloads that try to name
 * somebody else's account and somebody else's session.
 */
final class CorrectionRequestFormTest extends TestCase
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
    public function the_tile_is_on_the_attendance_screen_with_the_allowance_left_this_month(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(3);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->assertOk()
            ->assertSee(__('requests.tiles.correction'))
            ->assertSee(__('requests.tiles.badge_quota', ['count' => 3]));
    }

    /**
     * The tile stays, and says why it will not help.
     *
     * Hiding it would leave somebody hunting for a control that was there
     * last week; the sentence is the answer they were going to ask for.
     */
    #[Test]
    public function a_spent_allowance_leaves_the_tile_in_place_and_says_so(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(1);
        $employee = $this->signIn();

        $this->correctionRequest($employee, on: CarbonImmutable::parse('2026-09-10'));

        Livewire::test(Attendance::class)
            ->assertOk()
            ->assertSee(__('requests.tiles.correction'))
            ->assertSee(__('requests.tiles.badge_no_quota'));
    }

    #[Test]
    public function a_quota_of_zero_says_correction_requests_are_switched_off(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(0);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->assertOk()
            ->assertSee(__('requests.tiles.badge_corrections_off'));
    }

    #[Test]
    public function the_modal_opens_onto_the_allowance_before_the_first_field(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->assertMountedActionModalSee(__('corrections.employee.quota', [
                'remaining' => 3,
                'allowance' => 3,
                'date' => '2026-10-01',
            ]));
    }

    #[Test]
    public function a_spent_allowance_opens_a_modal_with_no_fields_and_no_submit_button(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(1);
        $employee = $this->signIn();

        $this->correctionRequest($employee, on: CarbonImmutable::parse('2026-09-10'));

        $page = Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->assertMountedActionModalSee(__('corrections.employee.quota_spent_heading'))
            ->assertMountedActionModalDontSee(__('corrections.fields.attendance_date'));

        $this->assertNull(
            $page->instance()->getMountedAction()?->getModalSubmitAction(),
            'A modal that cannot be sent must not offer a button that invites trying.',
        );
    }

    #[Test]
    public function a_quota_of_zero_opens_a_modal_that_says_corrections_are_switched_off(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(0);
        $this->signIn();

        $page = Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->assertMountedActionModalSee(__('corrections.employee.disabled_body'));

        $this->assertNull($page->instance()->getMountedAction()?->getModalSubmitAction());
    }

    #[Test]
    public function the_form_prints_what_the_system_recorded_for_the_chosen_day(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        $this->attendanceSession($employee, '08:00', '12:30', CarbonImmutable::parse('2026-09-14'));

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->setActionData(['attendance_date' => '2026-09-14'])
            ->assertMountedActionModalSee('08:00 – 12:30')
            ->assertMountedActionModalSee(__('corrections.sessions.device_recorded'));
    }

    /**
     * The ordinary day: one session recorded, so the radio is not offered
     * and the day picker chooses on the employee's behalf.
     *
     * The choice has to survive being sent. A hidden field is not dehydrated
     * by default, and when this one was dropped the workflow received a
     * request naming no session at all - so amending a check-in became
     * creating a second session on a day that already had one. This test
     * drives the modal the way the browser does, field by field, because a
     * payload handed over in one piece never fires the picker that makes the
     * choice.
     */
    #[Test]
    public function the_only_session_of_a_day_is_the_one_the_request_names(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        $session = $this->attendanceSession($employee, '09:40', '17:05', CarbonImmutable::parse('2026-09-14'));

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->set('mountedActions.0.data.attendance_date', '2026-09-14')
            ->set('mountedActions.0.data.reason', CorrectionReason::ForgotToRecord->value)
            ->set('mountedActions.0.data.requested_check_in_at', '08:15')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $request = AttendanceCorrection::query()->sole();

        $this->assertSame(
            $session->id,
            $request->attendance_id,
            'A correction on a one-session day must amend that session, not ask for another one.',
        );
        $this->assertSame('08:15', substr((string) $request->requested_check_in_time, 0, 5));
        // Only the half the employee named: the check-out was not mentioned
        // and must not be invented.
        $this->assertNull($request->requested_check_out_time);
    }

    #[Test]
    public function a_day_the_system_recorded_nothing_for_says_so_in_a_sentence(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->setActionData(['attendance_date' => '2026-09-14'])
            ->assertMountedActionModalSee(__('corrections.sessions.none'));
    }

    #[Test]
    public function a_corrected_session_is_not_labelled_as_recorded_from_a_location(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        $day = CarbonImmutable::parse('2026-09-14');
        $session = $this->attendanceSession($employee, '08:00', '12:30', $day);
        $request = $this->correctionRequest($employee, $session, '08:00', '12:30', $day);

        $session->forceFill([
            'original_check_out_at' => $session->check_out_at,
            'check_out_correction_id' => $request->id,
        ])->save();

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->setActionData(['attendance_date' => '2026-09-14'])
            ->assertMountedActionModalSee(__('attendance.badges.corrected'))
            ->assertMountedActionModalDontSee(__('corrections.sessions.device_recorded'));
    }

    #[Test]
    public function the_session_choice_appears_only_once_the_day_holds_two_of_them(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        $day = CarbonImmutable::parse('2026-09-14');
        $this->attendanceSession($employee, '08:00', '12:30', $day);

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->setActionData(['attendance_date' => '2026-09-14'])
            ->assertMountedActionModalDontSee(__('corrections.helpers.session'));

        $this->attendanceSession($employee, '13:00', '17:00', $day);

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->setActionData(['attendance_date' => '2026-09-14'])
            ->assertMountedActionModalSee(__('corrections.helpers.session'));
    }

    /**
     * A radio option is a plain string in an Arabic sentence, and a time
     * range dropped into one is reordered by the bidirectional algorithm
     * until the check-out is printed first. On this screen that is not a
     * cosmetic fault: it is the wrong claim about which moment is which.
     *
     * The isolate characters are the plain-text equivalent of dir="ltr",
     * which is what the block above the radio uses.
     */
    #[Test]
    public function a_session_option_keeps_the_check_in_before_the_check_out(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        $day = CarbonImmutable::parse('2026-09-14');
        $this->attendanceSession($employee, '08:00', '12:30', $day);
        $this->attendanceSession($employee, '13:00', '17:00', $day);

        Livewire::test(Attendance::class)
            ->mountAction(RequestCorrectionAction::NAME)
            ->setActionData(['attendance_date' => '2026-09-14'])
            ->assertMountedActionModalSee("\u{2066}08:00 – 12:30\u{2069}")
            ->assertMountedActionModalSee("\u{2066}13:00 – 17:00\u{2069}");
    }

    #[Test]
    public function a_day_with_two_sessions_will_not_be_sent_without_one_of_them_named(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        $day = CarbonImmutable::parse('2026-09-14');
        $this->attendanceSession($employee, '08:00', '12:30', $day);
        $this->attendanceSession($employee, '13:00', '17:00', $day);

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:15',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertHasActionErrors(['attendance_id']);

        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    #[Test]
    public function a_day_with_nothing_recorded_needs_both_times(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertHasActionErrors(['requested_check_out_at']);

        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    #[Test]
    public function a_check_out_before_the_check_in_is_refused(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '17:00',
                'requested_check_out_at' => '08:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertHasActionErrors(['requested_check_out_at']);

        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    /**
     * The picker's bounds are drawn in the browser and enforced on the
     * server, because a payload never went near the picker.
     */
    #[Test]
    public function a_hand_filled_day_outside_the_window_is_refused_by_the_server(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        $payload = [
            'requested_check_in_at' => '08:00',
            'requested_check_out_at' => '17:00',
            'reason' => CorrectionReason::ForgotToRecord->value,
        ];

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [...$payload, 'attendance_date' => '2026-07-31'])
            ->assertHasActionErrors(['attendance_date']);

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [...$payload, 'attendance_date' => '2026-09-16'])
            ->assertHasActionErrors(['attendance_date']);

        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    #[Test]
    public function working_remotely_needs_a_written_explanation_and_forgetting_does_not(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:00',
                'requested_check_out_at' => '17:00',
                'reason' => CorrectionReason::RemoteWork->value,
            ])
            ->assertHasActionErrors(['note']);

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:00',
                'requested_check_out_at' => '17:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, AttendanceCorrection::query()->count());
    }

    #[Test]
    public function a_sent_request_is_stored_pending_under_the_signed_in_account(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:00',
                'requested_check_out_at' => '17:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
                'note' => 'نسيت التسجيل صباحًا.',
            ])
            ->assertHasNoActionErrors()
            ->assertDispatched('correction-requested');

        $request = AttendanceCorrection::query()->sole();

        $this->assertSame($employee->id, $request->user_id);
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame('2026-09-14', $request->attendance_date->toDateString());
        $this->assertSame('08:00', substr((string) $request->requested_check_in_time, 0, 5));
        $this->assertSame('17:00', substr((string) $request->requested_check_out_time, 0, 5));
        $this->assertNull($request->decided_by_id);
        $this->assertNull($request->decided_at);
    }

    /**
     * A payload naming another account is not refused: it is ignored. The
     * workflow takes the employee from the session and never from the form,
     * and the model's #[Fillable] does not list user_id at all.
     */
    #[Test]
    public function a_payload_naming_another_account_still_files_under_the_signed_in_one(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $employee = $this->signIn();
        $somebodyElse = $this->makeEmployee('other@example.test');

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:00',
                'requested_check_out_at' => '17:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
                'user_id' => $somebodyElse->id,
                'status' => RequestStatus::Approved->value,
                'decided_by_id' => $somebodyElse->id,
            ])
            ->assertHasNoActionErrors();

        $request = AttendanceCorrection::query()->sole();

        $this->assertSame($employee->id, $request->user_id);
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertNull($request->decided_by_id);
    }

    #[Test]
    public function a_session_belonging_to_somebody_else_is_refused_by_the_workflow(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(3);
        $this->signIn();

        $somebodyElse = $this->makeEmployee('other@example.test');
        $theirSession = $this->attendanceSession($somebodyElse, '08:00', '12:30', CarbonImmutable::parse('2026-09-14'));

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'attendance_id' => $theirSession->id,
                'requested_check_in_at' => '08:15',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertActionHalted(RequestCorrectionAction::NAME);

        $this->assertSame(0, AttendanceCorrection::query()->count());
    }

    #[Test]
    public function a_second_pending_request_for_the_same_day_is_refused(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCorrectionQuota(5);
        $employee = $this->signIn();

        $this->correctionRequest($employee, on: CarbonImmutable::parse('2026-09-14'));

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => '2026-09-14',
                'requested_check_in_at' => '08:00',
                'requested_check_out_at' => '17:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertActionHalted(RequestCorrectionAction::NAME);

        $this->assertSame(1, AttendanceCorrection::query()->count());
    }

    /**
     * The correction throttle is its own bucket: spending it must never
     * stand between somebody and the button this product exists for.
     */
    #[Test]
    public function the_sixth_request_in_a_minute_is_throttled_and_check_in_still_works(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCompanyLocation();
        $this->configureCorrectionQuota(20);
        $this->signIn();

        foreach (range(1, 5) as $day) {
            Livewire::test(Attendance::class)
                ->callAction(RequestCorrectionAction::NAME, [
                    'attendance_date' => CarbonImmutable::parse(self::FROZEN_NOW)->subDays($day)->toDateString(),
                    'requested_check_in_at' => '08:00',
                    'requested_check_out_at' => '17:00',
                    'reason' => CorrectionReason::ForgotToRecord->value,
                ])
                ->assertHasNoActionErrors();
        }

        Livewire::test(Attendance::class)
            ->callAction(RequestCorrectionAction::NAME, [
                'attendance_date' => CarbonImmutable::parse(self::FROZEN_NOW)->subDays(6)->toDateString(),
                'requested_check_in_at' => '08:00',
                'requested_check_out_at' => '17:00',
                'reason' => CorrectionReason::ForgotToRecord->value,
            ])
            ->assertActionHalted(RequestCorrectionAction::NAME);

        $this->assertSame(5, AttendanceCorrection::query()->count());

        Livewire::test(Attendance::class)
            ->call('checkIn', $this->payloadAtCompany())
            ->assertSet('feedbackStatus', 'success');
    }

    /**
     * @return array<string, float>
     */
    private function payloadAtCompany(): array
    {
        $reading = $this->readingAtCompany();

        return [
            'latitude' => $reading->coordinates->latitude,
            'longitude' => $reading->coordinates->longitude,
            'accuracy' => $reading->accuracyMeters,
        ];
    }

    private function signIn(): User
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        return $employee;
    }
}
