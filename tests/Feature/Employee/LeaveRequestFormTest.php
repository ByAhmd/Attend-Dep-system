<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Filament\Employee\Actions\RequestLeaveAction;
use App\Filament\Employee\Pages\Attendance;
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
 * The leave form as an employee meets it, including the one file this
 * product accepts from anybody.
 *
 * The attachment tests are the reason this file fakes a disk: what is being
 * proved is that a file the product will not carry never reaches it, and
 * that the size and type recorded beside a request describe the bytes that
 * landed rather than whatever the browser said about them.
 */
final class LeaveRequestFormTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-09-15 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
        Storage::fake(LeaveAttachmentStore::DISK);
    }

    #[Test]
    public function the_tile_is_on_the_attendance_screen(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->configureCompanyLocation();
        $this->signIn();

        Livewire::test(Attendance::class)
            ->assertOk()
            ->assertSee(__('requests.tiles.leave'))
            ->assertSee(__('requests.tiles.caption_leave'));
    }

    #[Test]
    public function a_request_needs_a_type_two_dates_and_a_written_reason(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [])
            ->assertHasActionErrors(['type', 'starts_on', 'ends_on', 'reason']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    #[Test]
    public function a_reason_of_a_few_characters_is_not_a_reason(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-09-20',
                'ends_on' => '2026-09-22',
                'reason' => 'سفر',
            ])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    #[Test]
    public function an_end_before_the_start_is_refused(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-09-22',
                'ends_on' => '2026-09-20',
                'reason' => 'إجازة عائلية مخططة مسبقًا.',
            ])
            ->assertHasActionErrors(['ends_on']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    #[Test]
    public function a_span_longer_than_the_maximum_is_refused(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        $start = CarbonImmutable::parse('2026-09-20');

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Unpaid->value,
                'starts_on' => $start->toDateString(),
                // Ninety-one days counted inclusively, which is one more
                // than a single request may cover.
                'ends_on' => $start->addDays(LeaveRequest::MAX_DAYS)->toDateString(),
                'reason' => 'إجازة طويلة بدون راتب لظرف عائلي.',
            ])
            ->assertHasActionErrors(['ends_on']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    #[Test]
    public function a_start_outside_the_allowed_window_is_refused_by_the_server(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        $payload = [
            'type' => LeaveType::Annual->value,
            'reason' => 'إجازة عائلية مخططة مسبقًا.',
        ];

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                ...$payload,
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-02',
            ])
            ->assertHasActionErrors(['starts_on']);

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                ...$payload,
                'starts_on' => '2028-01-01',
                'ends_on' => '2028-01-02',
            ])
            ->assertHasActionErrors(['starts_on']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    /**
     * A start in the recent past is the normal case, not the exception: a
     * sick day is reported after it has been taken.
     */
    #[Test]
    public function a_sick_day_already_taken_can_still_be_reported(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Sick->value,
                'starts_on' => '2026-09-13',
                'ends_on' => '2026-09-14',
                'reason' => 'كنت مريضًا ولم أتمكن من الحضور.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    #[Test]
    public function leaving_and_coming_back_collapses_the_range_to_one_day(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-09-20',
                // Whatever the end picker held before the toggle was pressed
                // is not what the employee meant once it is on.
                'ends_on' => '2026-09-30',
                'is_exit_and_return' => true,
                'reason' => 'موعد طبي في منتصف اليوم.',
            ])
            ->assertHasNoActionErrors();

        $request = LeaveRequest::query()->sole();

        $this->assertTrue($request->is_exit_and_return);
        $this->assertSame('2026-09-20', $request->starts_on->toDateString());
        $this->assertSame('2026-09-20', $request->ends_on->toDateString());
        $this->assertSame(1, $request->dayCount());
    }

    #[Test]
    public function a_sent_request_is_stored_pending_under_the_signed_in_account(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-09-20',
                'ends_on' => '2026-09-24',
                'reason' => 'إجازة عائلية مخططة مسبقًا.',
            ])
            ->assertHasNoActionErrors()
            ->assertDispatched('leave-requested');

        $request = LeaveRequest::query()->sole();

        $this->assertSame($employee->id, $request->user_id);
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame(LeaveType::Annual, $request->type);
        $this->assertSame(5, $request->dayCount());
        $this->assertNull($request->decided_by_id);
        $this->assertNull($request->attachment_path);
    }

    #[Test]
    public function a_payload_naming_another_account_or_a_decision_changes_neither(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();
        $somebodyElse = $this->makeEmployee('other@example.test');

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-09-20',
                'ends_on' => '2026-09-21',
                'reason' => 'إجازة عائلية مخططة مسبقًا.',
                'user_id' => $somebodyElse->id,
                'status' => RequestStatus::Approved->value,
                'decided_by_id' => $somebodyElse->id,
                'decision_note' => 'وافقت على نفسي.',
            ])
            ->assertHasNoActionErrors();

        $request = LeaveRequest::query()->sole();

        $this->assertSame($employee->id, $request->user_id);
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertNull($request->decided_by_id);
        $this->assertNull($request->decision_note);
    }

    #[Test]
    public function a_second_request_covering_the_same_days_is_refused(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signIn();

        $this->leaveRequest(
            $employee,
            CarbonImmutable::parse('2026-09-20'),
            CarbonImmutable::parse('2026-09-24'),
        );

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-09-22',
                'ends_on' => '2026-09-26',
                'reason' => 'إجازة عائلية مخططة مسبقًا.',
            ])
            ->assertActionHalted(RequestLeaveAction::NAME);

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    /*
     |--------------------------------------------------------------------
     | The supporting document
     |--------------------------------------------------------------------
     |
     | These four run on the real clock and name their days relative to it.
     | Livewire sweeps temporary uploads it judges more than a day old, and
     | a clock frozen a week ahead of the machine makes every freshly
     | uploaded file look ancient the instant it is written - so a frozen
     | clock here would prove nothing about the ceiling and everything about
     | the sweep.
     */

    #[Test]
    public function a_supporting_document_is_stored_with_the_size_and_type_the_disk_reports(): void
    {
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, $this->sickLeaveWith([
                UploadedFile::fake()->createWithContent('report.pdf', str_repeat('0', 2048)),
            ]))
            ->assertHasNoActionErrors();

        $request = LeaveRequest::query()->sole();

        $this->assertNotNull($request->attachment_path);
        $this->assertSame('report.pdf', $request->attachment_name);
        $this->assertSame('application/pdf', $request->attachment_mime_type);
        // Read back off the disk rather than taken from the upload: what an
        // approver will be handed is what the row has to describe.
        $this->assertSame(
            Storage::disk(LeaveAttachmentStore::DISK)->size((string) $request->attachment_path),
            $request->attachment_size,
        );

        Storage::disk(LeaveAttachmentStore::DISK)->assertExists((string) $request->attachment_path);
    }

    /**
     * Refused by FileUpload::acceptedFileTypes(), which becomes a
     * `mimetypes` rule on the submitted file.
     */
    #[Test]
    public function an_executable_is_not_a_supporting_document(): void
    {
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, $this->sickLeaveWith([
                UploadedFile::fake()->createWithContent('payload.exe', str_repeat('0', 2048)),
            ]))
            ->assertHasActionErrors(['attachment_path']);

        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertSame([], Storage::disk(LeaveAttachmentStore::DISK)->allFiles());
    }

    /**
     * Refused by FileUpload::maxSize(), which is the store's own ceiling
     * expressed in the unit Laravel counts files in.
     */
    #[Test]
    public function a_file_over_the_four_megabyte_ceiling_is_refused(): void
    {
        $this->signIn();

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, $this->sickLeaveWith([
                UploadedFile::fake()->createWithContent('scan.pdf', str_repeat('0', 5 * 1024 * 1024)),
            ]))
            ->assertHasActionErrors(['attachment_path']);

        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertSame([], Storage::disk(LeaveAttachmentStore::DISK)->allFiles());
    }

    /**
     * A file ten times the ceiling never reaches this application at all.
     *
     * Livewire validates a temporary upload at its own endpoint, before any
     * schema, action or workflow of ours is asked anything, so forty
     * megabytes is refused where it costs least: at the door, with nothing
     * written to either disk and no state for the form to carry.
     */
    #[Test]
    public function a_forty_megabyte_file_is_refused_at_the_upload_endpoint(): void
    {
        $this->signIn();

        $page = Livewire::test(Attendance::class)
            ->mountAction(RequestLeaveAction::NAME)
            ->set('mountedActions.0.data.attachment_path', [
                UploadedFile::fake()->create('scan.pdf', 40 * 1024, 'application/pdf'),
            ])
            ->assertHasErrors('mountedActions.0.data.attachment_path.0');

        $this->assertSame([], $page->get('mountedActions')[0]['data']['attachment_path']);
        $this->assertSame([], Storage::disk(LeaveAttachmentStore::DISK)->allFiles());
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    #[Test]
    public function the_sixth_request_in_a_minute_is_throttled(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signIn();

        foreach (range(0, 4) as $offset) {
            $day = CarbonImmutable::parse('2026-09-20')->addDays($offset * 2);

            Livewire::test(Attendance::class)
                ->callAction(RequestLeaveAction::NAME, [
                    'type' => LeaveType::Annual->value,
                    'starts_on' => $day->toDateString(),
                    'ends_on' => $day->toDateString(),
                    'reason' => 'إجازة عائلية مخططة مسبقًا.',
                ])
                ->assertHasNoActionErrors();
        }

        Livewire::test(Attendance::class)
            ->callAction(RequestLeaveAction::NAME, [
                'type' => LeaveType::Annual->value,
                'starts_on' => '2026-10-20',
                'ends_on' => '2026-10-20',
                'reason' => 'إجازة عائلية مخططة مسبقًا.',
            ])
            ->assertActionHalted(RequestLeaveAction::NAME);

        $this->assertSame(5, LeaveRequest::query()->count());
    }

    /**
     * A sick-leave payload carrying one file, on days the real clock has
     * not reached yet.
     *
     * @param  list<UploadedFile>  $attachment
     * @return array<string, mixed>
     */
    private function sickLeaveWith(array $attachment): array
    {
        return [
            'type' => LeaveType::Sick->value,
            'starts_on' => CarbonImmutable::now()->addDay()->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(2)->toDateString(),
            'reason' => 'تقرير طبي مرفق مع الطلب.',
            'attachment_path' => $attachment,
        ];
    }

    private function signIn(): User
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        return $employee;
    }
}
