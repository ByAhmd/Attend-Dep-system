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
use App\Services\Requests\RequestAudience;
use App\Support\Filament\RequestNoticeLink;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * A notification is a message about one person's business, so the questions
 * here are about who may read one and what one is allowed to say.
 *
 * The bell has no buttons for another person's rows, which is exactly why
 * every attack below is a hand-crafted Livewire call rather than a click:
 * `markedNotificationAsRead` and `notificationClosed` are browser events
 * carrying a bare uuid, and anybody with the developer console open can
 * dispatch either one with somebody else's. The defence is that every one of
 * those handlers runs through `getNotificationsQuery()`, which is the
 * reader's own `notifications()` relation and nothing wider - so the whole
 * class of attack fails on a WHERE clause rather than on a check somebody
 * has to remember to write. That is worth pinning, because a future
 * getNotification() override or a widened query would take the floor out
 * from under it silently.
 *
 * The second half is about the words. An administrator's line may say no
 * more than the queue it links to already shows, and an employee's answer
 * may name nobody but its reader - not the colleague whose request was
 * decided in the same minute, and not the administrator who decided it.
 */
final class NotificationSecurityTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $sara;

    private User $omar;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 09:00:00');

        config(['admin.super_admin_email' => null]);

        $this->sara = $this->makeEmployee('sara@company.test');
        $this->omar = $this->makeEmployee('omar@company.test');
        $this->administrator = $this->makeAdmin('first@company.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A decision addressed to one person, written straight into their bell so
     * the test is about reading it rather than about the workflow that made
     * it.
     */
    private function tell(User $reader, RequestStatus $status = RequestStatus::Rejected, ?string $note = 'The door log disagrees.'): string
    {
        $reader->notify(new RequestDecisionNotification(new RequestNotice(
            kind: RequestKind::Correction,
            requestId: 7,
            employeeName: $reader->name,
            from: CarbonImmutable::parse('2026-09-09'),
            until: null,
            status: $status,
            note: $note,
        )));

        return (string) $reader->notifications()->firstOrFail()->getKey();
    }

    private function bell(User $reader, string $panel = 'employee'): Testable
    {
        Filament::setCurrentPanel($panel);

        $this->actingAs($reader);

        return Livewire::test(RequestNotices::class);
    }

    #[Test]
    public function one_employees_bell_never_holds_another_employees_line(): void
    {
        $this->tell($this->sara);

        $this->bell($this->omar)
            ->assertOk()
            ->assertDontSee('The door log disagrees.')
            ->assertDontSee($this->sara->name);

        $this->assertSame(0, $this->omar->notifications()->count());
    }

    #[Test]
    public function marking_another_employees_line_as_read_does_nothing_to_it(): void
    {
        $id = $this->tell($this->sara);

        // The event the browser sends when a line is opened, dispatched by
        // hand with somebody else's uuid in it.
        $this->bell($this->omar)->dispatch('markedNotificationAsRead', $id);

        $this->assertNull($this->sara->notifications()->firstOrFail()->read_at);
    }

    #[Test]
    public function marking_another_employees_line_as_unread_does_nothing_to_it(): void
    {
        $id = $this->tell($this->sara);
        $this->sara->notifications()->update(['read_at' => now()]);

        $this->bell($this->omar)->dispatch('markedNotificationAsUnread', $id);

        $this->assertNotNull($this->sara->notifications()->firstOrFail()->read_at);
    }

    #[Test]
    public function closing_another_employees_line_does_not_delete_it(): void
    {
        $id = $this->tell($this->sara);

        // notificationClosed is the destructive one: the handler behind it
        // deletes the row outright.
        $this->bell($this->omar)->dispatch('notificationClosed', $id);

        $this->assertSame(1, $this->sara->notifications()->count());
    }

    #[Test]
    public function clearing_your_own_bell_leaves_everybody_elses_alone(): void
    {
        $this->tell($this->sara);
        $this->tell($this->omar);

        $this->bell($this->omar)->call('clearNotifications');

        $this->assertSame(0, $this->omar->notifications()->count());
        $this->assertSame(1, $this->sara->notifications()->count());
    }

    #[Test]
    public function marking_everything_read_marks_only_your_own(): void
    {
        $this->tell($this->sara);
        $this->tell($this->omar);

        $this->bell($this->omar)->call('markAllNotificationsAsRead');

        $this->assertNotNull($this->omar->notifications()->firstOrFail()->read_at);
        $this->assertNull($this->sara->notifications()->firstOrFail()->read_at);
    }

    #[Test]
    public function an_employee_cannot_read_an_administrators_line_about_a_colleague(): void
    {
        $this->administrator->notify(new NewRequestNotification(new RequestNotice(
            kind: RequestKind::Leave,
            requestId: 11,
            employeeName: $this->sara->name,
            from: CarbonImmutable::parse('2026-09-20'),
            until: CarbonImmutable::parse('2026-09-22'),
            status: RequestStatus::Pending,
            note: null,
        )));

        $this->bell($this->omar)
            ->assertOk()
            ->assertDontSee($this->sara->name)
            ->assertDontSee('2026-09-20');

        $this->assertSame(0, $this->omar->notifications()->count());
    }

    #[Test]
    public function an_employee_who_follows_an_administrators_link_is_refused_the_queue(): void
    {
        $url = RequestNoticeLink::for(new RequestNotice(
            kind: RequestKind::Correction,
            requestId: 11,
            employeeName: $this->sara->name,
            from: CarbonImmutable::parse('2026-09-09'),
            until: null,
            status: RequestStatus::Pending,
            note: null,
        ));

        $this->actingAs($this->omar)->get($url)->assertForbidden();
    }

    #[Test]
    public function a_bell_with_nobody_signed_in_answers_nothing_at_all(): void
    {
        $this->tell($this->sara);

        Filament::setCurrentPanel('employee');

        $bell = Livewire::test(RequestNotices::class);

        $bell->assertStatus(401);
        $bell->assertDontSee('The door log disagrees.');
    }

    #[Test]
    public function an_answer_names_nobody_but_the_person_reading_it(): void
    {
        // Two requests decided in the same minute. Sara's answer must carry
        // no trace of Omar's.
        $this->tell($this->sara, note: 'Sara, the door log disagrees.');
        $this->tell($this->omar, note: 'Omar, approved on the manager word.');

        $this->bell($this->sara)
            ->assertOk()
            ->assertSee('Sara, the door log disagrees.')
            ->assertDontSee('Omar')
            ->assertDontSee($this->omar->name);
    }

    #[Test]
    public function an_answer_does_not_say_which_administrator_gave_it(): void
    {
        $this->tell($this->sara, RequestStatus::Approved, null);

        $payload = (array) $this->sara->notifications()->firstOrFail()->data;

        // decided_by is a column on the request and is deliberately not one
        // of these keys: the account that answers a request may be the super
        // administrator, and nothing in this interface may name that account.
        $this->assertSame(
            ['format', 'kind', 'request_id', 'employee_name', 'from', 'until', 'status', 'note'],
            array_keys($payload),
        );

        $this->bell($this->sara)->assertOk()->assertDontSee($this->administrator->name);
    }

    #[Test]
    public function an_administrators_line_says_no_more_than_the_queue_already_shows(): void
    {
        $this->configureCompanyLocation();

        $request = $this->leaveRequest(
            $this->sara,
            from: CarbonImmutable::parse('2026-09-20'),
            until: CarbonImmutable::parse('2026-09-22'),
        );

        $request->forceFill(['reason' => 'A private matter I would rather not print.'])->save();

        $this->administrator->notify(new NewRequestNotification(RequestNotice::about($request->refresh())));

        $payload = (array) $this->administrator->notifications()->firstOrFail()->data;

        // The employee's own written reason - and on a correction, the note
        // they attached to it - stays in the record. A bell is a summons to
        // go and read the request, not a copy of it.
        $this->assertArrayNotHasKey('reason', $payload);
        $this->assertNull($payload['note']);

        $this->bell($this->administrator, 'admin')
            ->assertOk()
            ->assertDontSee('A private matter I would rather not print.');
    }

    #[Test]
    public function the_pinned_account_is_not_told_about_its_own_request(): void
    {
        config(['admin.super_admin_email' => 'owner@company.test']);

        $owner = $this->makeAdmin('owner@company.test');

        // The requester is excluded outside the role-or-pin group, not
        // inside it. A stray parenthesis there would put the owner in the
        // audience for their own request and nobody else in the audience for
        // theirs - a difference the administrator on the next desk could see.
        $this->assertSame(
            [$this->administrator->getKey()],
            app(RequestAudience::class)->administratorsOtherThan($owner)->modelKeys(),
        );
    }

    #[Test]
    public function a_trashed_pinned_account_is_told_no_more_than_a_trashed_colleague(): void
    {
        config(['admin.super_admin_email' => 'owner@company.test']);

        $owner = $this->makeAdmin('owner@company.test');
        $colleague = $this->makeAdmin('second@company.test');

        // Only a hand in the database can do this - the policy refuses to
        // delete the pinned account - and the soft-delete scope deliberately
        // goes on showing the row afterwards so the owner is never locked
        // out. The audience must not inherit that exemption.
        $owner->delete();
        $colleague->delete();

        $audience = app(RequestAudience::class)->administratorsOtherThan($this->sara);

        $this->assertSame([$this->administrator->getKey()], $audience->modelKeys());
    }

    #[Test]
    public function an_ordinary_administrator_looking_at_their_bell_sees_no_trace_of_the_pinned_account(): void
    {
        config(['admin.super_admin_email' => 'owner@company.test']);

        $owner = $this->makeAdmin('owner@company.test');

        $notice = new RequestNotice(
            kind: RequestKind::Correction,
            requestId: 3,
            employeeName: $this->sara->name,
            from: CarbonImmutable::parse('2026-09-09'),
            until: null,
            status: RequestStatus::Pending,
            note: null,
        );

        foreach ([$owner, $this->administrator] as $reader) {
            $reader->notify(new NewRequestNotification($notice));
        }

        // Not the address, not the name, and not a count that differs from
        // the one the owner is looking at on the next desk.
        $this->bell($this->administrator, 'admin')
            ->assertOk()
            ->assertDontSee('owner@company.test')
            ->assertDontSee($owner->name);

        $this->assertSame(
            $owner->unreadNotifications()->count(),
            $this->administrator->unreadNotifications()->count(),
        );
    }

    #[Test]
    public function markup_somebody_typed_is_never_drawn_as_markup(): void
    {
        $this->tell($this->sara, note: '<script>alert(1)</script><img src=x onerror=alert(2)>');

        $this->bell($this->sara)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertDontSee('onerror=', escape: false);
    }
}
