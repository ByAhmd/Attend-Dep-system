<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Requests\RequestNotice;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Filament\Notifications\RequestNotices;
use App\Models\User;
use App\Notifications\NewRequestNotification;
use App\Notifications\RequestDecisionNotification;
use App\Support\Filament\RequestNoticeLink;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification as StoredNotification;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The bell itself: what a stored row looks like once somebody opens it.
 *
 * The rows hold facts, so this is where the sentence is made, and the tests
 * that matter most here are the ones about language. A notice written while
 * its reader was in Arabic is read again in English and has to be English:
 * that is the whole reason the row does not hold a rendered string, and it
 * is provable only from this side.
 *
 * The link is asserted rather than the click, because the link is the part
 * that can be wrong in a way nobody notices - the two panels read the same
 * rows, so a URL resolved against "the current panel" would work perfectly
 * for whoever built it and 404 for everybody else.
 */
final class NotificationBellTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $employee;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 09:00:00');

        config(['admin.super_admin_email' => null]);

        $this->employee = $this->makeEmployee('sara@company.test');
        $this->administrator = $this->makeAdmin('first@company.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A value as the notice writes it into a sentence: closed off with a
     * first-strong isolate so its script cannot rearrange the line around it.
     */
    private static function isolate(string $value): string
    {
        return "\u{2068}".$value."\u{2069}";
    }

    private function notice(
        RequestKind $kind = RequestKind::Correction,
        RequestStatus $status = RequestStatus::Pending,
        ?string $note = null,
        ?string $until = null,
    ): RequestNotice {
        return new RequestNotice(
            kind: $kind,
            requestId: 42,
            employeeName: 'Sara Alharbi',
            from: CarbonImmutable::parse('2026-09-09'),
            until: $until === null ? null : CarbonImmutable::parse($until),
            status: $status,
            note: $note,
        );
    }

    /**
     * The one row in somebody's bell, as the component draws it.
     */
    private function drawn(User $reader, string $panel = 'admin'): FilamentNotification
    {
        Filament::setCurrentPanel($panel);

        $this->actingAs($reader);

        $row = $reader->notifications()->firstOrFail();

        $this->assertInstanceOf(StoredNotification::class, $row);

        // Mounted rather than newed up, so the component is asserted through
        // the same path a reader's browser takes to it.
        $bell = Livewire::test(RequestNotices::class);
        $bell->assertOk();

        $component = $bell->instance();

        $this->assertInstanceOf(RequestNotices::class, $component);

        return $component->getNotification($row);
    }

    #[Test]
    public function an_arriving_request_reads_as_a_sentence_in_arabic(): void
    {
        App::setLocale('ar');

        $this->administrator->notify(new NewRequestNotification($this->notice()));

        $drawn = $this->drawn($this->administrator);

        $this->assertSame('طلب تصحيح جديد', $drawn->getTitle());
        $this->assertSame(self::isolate('Sara Alharbi').' — 2026-09-09', $drawn->getBody());
    }

    #[Test]
    public function the_same_stored_row_reads_in_english_for_a_reader_who_switched(): void
    {
        App::setLocale('ar');

        $this->administrator->notify(new NewRequestNotification($this->notice()));

        // The row was written by an Arabic screen. The reader has since
        // changed the language cookie, and nothing about the row knows or
        // needs to.
        App::setLocale('en');

        $drawn = $this->drawn($this->administrator);

        $this->assertSame('New correction request', $drawn->getTitle());
        $this->assertSame(self::isolate('Sara Alharbi').' — 2026-09-09', $drawn->getBody());
    }

    #[Test]
    public function a_rejection_puts_the_administrators_note_where_the_employee_will_read_it(): void
    {
        App::setLocale('en');

        $this->employee->notify(new RequestDecisionNotification(
            $this->notice(status: RequestStatus::Rejected, note: 'The door log shows 08:00.'),
        ));

        $drawn = $this->drawn($this->employee, panel: 'employee');

        $this->assertSame('Your correction request was rejected', $drawn->getTitle());
        $this->assertSame('2026-09-09 — '.self::isolate('The door log shows 08:00.'), $drawn->getBody());
        $this->assertSame('danger', $drawn->getStatus());
    }

    #[Test]
    public function an_approval_with_no_note_still_reads_as_a_finished_sentence(): void
    {
        App::setLocale('en');

        $this->employee->notify(new RequestDecisionNotification(
            $this->notice(status: RequestStatus::Approved),
        ));

        $drawn = $this->drawn($this->employee, panel: 'employee');

        $this->assertSame('Your correction request was approved', $drawn->getTitle());

        // No dangling dash where a note is not.
        $this->assertSame('2026-09-09', $drawn->getBody());
        $this->assertSame('success', $drawn->getStatus());
    }

    #[Test]
    public function an_arabic_name_on_an_english_screen_does_not_turn_the_line_around(): void
    {
        App::setLocale('en');

        $notice = new RequestNotice(
            kind: RequestKind::Correction,
            requestId: 42,
            employeeName: 'سارة الحربي',
            from: CarbonImmutable::parse('2026-09-09'),
            until: null,
            status: RequestStatus::Pending,
            note: null,
        );

        // Without the isolate the bidirectional algorithm reads the date as
        // right-to-left, joins it to the Arabic name through the neutral em
        // dash, and draws the whole line backwards: the date first, then the
        // name. The characters are invisible and the assertion is the only
        // place their absence would show.
        $this->assertSame(self::isolate('سارة الحربي').' — 2026-09-09', $notice->body());
    }

    #[Test]
    public function a_leave_period_reads_as_two_days_in_both_languages(): void
    {
        $notice = $this->notice(kind: RequestKind::Leave, until: '2026-09-12');

        App::setLocale('en');
        $this->assertSame('From 2026-09-09 to 2026-09-12', $notice->period());

        App::setLocale('ar');
        $this->assertSame('من 2026-09-09 إلى 2026-09-12', $notice->period());
    }

    #[Test]
    public function one_day_of_leave_reads_as_one_date_rather_than_a_range(): void
    {
        $this->assertSame(
            '2026-09-09',
            $this->notice(kind: RequestKind::Leave, until: '2026-09-09')->period(),
        );
    }

    #[Test]
    public function an_arriving_correction_opens_that_record_in_the_admin_queue(): void
    {
        $url = RequestNoticeLink::for($this->notice());

        $this->assertStringContainsString('/admin/attendance-corrections', $url);
        $this->assertStringContainsString('tableAction=view', $url);
        $this->assertStringContainsString('tableActionRecord=42', $url);
    }

    #[Test]
    public function an_arriving_leave_request_opens_that_record_in_the_leave_queue(): void
    {
        $url = RequestNoticeLink::for($this->notice(kind: RequestKind::Leave));

        $this->assertStringContainsString('/admin/leave-requests', $url);
        $this->assertStringContainsString('tableActionRecord=42', $url);
    }

    #[Test]
    public function a_decision_opens_the_employees_own_requests_page(): void
    {
        $url = RequestNoticeLink::for($this->notice(status: RequestStatus::Approved));

        $this->assertStringEndsWith('/requests', $url);
        $this->assertStringNotContainsString('/admin', $url);
    }

    #[Test]
    public function a_link_names_its_own_panel_wherever_it_is_drawn(): void
    {
        $this->employee->notify(new RequestDecisionNotification(
            $this->notice(status: RequestStatus::Approved),
        ));
        $this->administrator->notify(new NewRequestNotification($this->notice()));

        // An administrator is an employee here too, so both bells draw both
        // kinds. Each line has to reach the panel it belongs to from either
        // one, which is why the URL never asks which panel it is standing in.
        $fromAdminPanel = $this->drawn($this->administrator, panel: 'admin');
        $fromEmployeePanel = $this->drawn($this->employee, panel: 'employee');

        $this->assertStringContainsString('/admin/attendance-corrections', $this->openUrl($fromAdminPanel));
        $this->assertStringEndsWith('/requests', $this->openUrl($fromEmployeePanel));
    }

    #[Test]
    public function a_line_in_the_bell_does_not_delete_itself_while_it_is_being_read(): void
    {
        $this->administrator->notify(new NewRequestNotification($this->notice()));

        // A Filament notification carries a duration and its default is six
        // seconds; when it elapses the browser dispatches notificationClosed,
        // and the component's handler for that DELETES the row. A bell that
        // emptied itself a few seconds after being opened would lose the
        // notice and the request behind it, silently and for good.
        $this->assertSame('persistent', $this->drawn($this->administrator)->getDuration());
    }

    #[Test]
    public function a_row_this_application_did_not_write_is_left_to_filament(): void
    {
        // Filament's own shape - a rendered title and body. Nothing here
        // writes one, but a deployment that once did would still have rows,
        // and a bell that fataled on one is a bell nobody can open.
        $this->administrator->notify(new NewRequestNotification($this->notice()));

        $row = $this->administrator->notifications()->firstOrFail();
        $row->forceFill(['data' => ['format' => 'filament', 'title' => 'Something older']])->save();

        $this->assertSame('Something older', $this->drawn($this->administrator)->getTitle());
    }

    #[Test]
    public function the_bell_is_drawn_in_both_panels(): void
    {
        foreach (['admin', 'employee'] as $panel) {
            $this->assertTrue(
                Filament::getPanel($panel)->hasDatabaseNotifications(),
                "The {$panel} panel draws no bell.",
            );

            $this->assertSame(
                RequestNotices::class,
                Filament::getPanel($panel)->getDatabaseNotificationsLivewireComponent(),
                "The {$panel} panel is drawing Filament's bell rather than the one that translates a stored notice.",
            );
        }
    }

    /**
     * The address of a drawn notification's one action.
     */
    private function openUrl(FilamentNotification $notification): string
    {
        $actions = $notification->getActions();

        $this->assertCount(1, $actions);

        $action = $actions[0];

        $this->assertInstanceOf(Action::class, $action);

        return (string) $action->getUrl();
    }
}
