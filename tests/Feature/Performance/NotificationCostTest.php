<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Data\Attendance\CorrectionDraft;
use App\Enums\CorrectionReason;
use App\Filament\Notifications\RequestNotices;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Filament\Widgets\RequestsQueueWidget;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What the bell costs, which is almost entirely a question about polling.
 *
 * The server is in the Netherlands and the staff are in Riyadh, so a round
 * trip is roughly 300ms before anything is computed. A bell that polls is
 * therefore not a cheap query on a busy server; it is a request per tab per
 * interval, forever, and on the employee panel those tabs are phones on
 * mobile data left open for a shift. Filament's default of thirty seconds
 * would be around a thousand requests per person per working day to announce
 * something that happens a few times a month.
 *
 * So the intervals are decisions, and they are pinned here as decisions
 * rather than as literals: the employee panel does not poll at all, and the
 * admin panel polls no more often than once every five minutes. Writing '5m'
 * or '600s' later would still pass; putting '5s' on either would not, which
 * is the whole point of the file.
 *
 * The other half is what one request costs when it is filed. Every
 * administrator is notified, and Laravel's database channel is one INSERT
 * per recipient - that is the obvious cost and it is not avoidable without
 * leaving the framework. What must not happen is a SELECT per administrator
 * on top of it, so the delta is measured rather than the total.
 */
final class NotificationCostTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-10 09:00:00');

        // Enough allowance that the monthly ration never becomes the reason
        // a measurement stops early; nothing here is about the quota.
        $this->configureCorrectionQuota(20);

        config(['admin.super_admin_email' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A polling interval as a number of seconds, or null for "never".
     *
     * Filament writes the string straight into `wire:poll.{interval}`, so
     * the accepted spellings are Alpine's - '30s', '5m', and a bare number
     * of milliseconds.
     */
    private function pollSeconds(?string $interval): ?float
    {
        if ($interval === null) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(ms|s|m)?$/', $interval, $matches) !== 1) {
            $this->fail("The polling interval '{$interval}' is not something wire:poll understands.");
        }

        $value = (float) $matches[1];

        return match ($matches[2] ?? 'ms') {
            'm' => $value * 60,
            's' => $value,
            default => $value / 1000,
        };
    }

    /**
     * Queries run while the callback executes.
     *
     * @param  callable():mixed  $work
     */
    private function queryCount(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function fileCorrection(User $employee, string $day): AttendanceCorrection
    {
        $date = CarbonImmutable::parse($day);

        $this->attendanceSession($employee, '08:00', '17:00', $date);

        return app(AttendanceCorrectionWorkflow::class)->submit($employee, new CorrectionDraft(
            date: $date,
            attendanceId: null,
            reason: CorrectionReason::ForgotToRecord,
            checkInTime: '07:30',
            checkOutTime: '16:00',
            note: null,
        ));
    }

    #[Test]
    public function the_employee_bell_never_polls(): void
    {
        // A phone at the door, on mobile data, with the page open for a
        // shift. Anything but null here spends somebody's data allowance all
        // day to deliver news that is already waiting on طلباتي.
        $this->assertNull(
            Filament::getPanel('employee')->getDatabaseNotificationsPollingInterval(),
            'The employee panel polls the server on a schedule; it must fill its bell on page load and then stop.',
        );
    }

    #[Test]
    public function the_admin_bell_polls_no_more_than_once_every_five_minutes(): void
    {
        $seconds = $this->pollSeconds(
            Filament::getPanel('admin')->getDatabaseNotificationsPollingInterval(),
        );

        $this->assertNotNull($seconds, 'The admin bell stopped polling entirely; a queue nobody is watching goes stale.');

        $this->assertGreaterThanOrEqual(
            300.0,
            $seconds,
            "The admin bell polls every {$seconds}s. Each poll is a round trip to a server 300ms away and re-renders the whole list; five minutes is the shortest interval this company's request volume justifies.",
        );
    }

    #[Test]
    public function nothing_on_the_dashboard_polls_faster_than_the_bell_does(): void
    {
        // Filament polls a stats widget every five seconds unless told
        // otherwise, and both dashboard widgets were inheriting it: two round
        // trips to Riyadh twelve times a minute, for as long as an
        // administrator leaves the page open. Pinning the bell at five
        // minutes while the widgets beside it ran at five seconds would have
        // been an argument the screen itself contradicted.
        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin('first@company.test'));

        // Asserted on the attribute the browser actually receives, because
        // that is the thing that costs a request; the property behind it is
        // protected and could be satisfied without the markup changing.
        foreach ([RequestsQueueWidget::class, AttendanceStatsWidget::class] as $widget) {
            Livewire::test($widget)->assertDontSee('wire:poll', escape: false);
        }
    }

    #[Test]
    public function the_page_the_browser_receives_carries_exactly_the_polling_that_was_decided(): void
    {
        // The two tests above ask the panel what it was configured with. This
        // one asks the HTML, because `wire:poll` is the thing that actually
        // costs a request and it is written by Filament's own blade from a
        // setter whose spelling is Filament's business, not ours. A version
        // that stopped honouring the setter would leave both of those tests
        // green and put the stock thirty seconds back on every open tab.
        $administrator = $this->makeAdmin('first@company.test');

        Filament::setCurrentPanel('admin');
        $admin = (string) $this->actingAs($administrator)->get('/admin')->getContent();

        preg_match_all('/wire:poll[^\s>="]*/', $admin, $matches);

        // Every poll on the administrator's dashboard is the bell's, and the
        // bell's is five minutes. Two stats widgets used to sit beside it at
        // Filament's default of five seconds.
        $this->assertSame(['wire:poll.300s'], array_values(array_unique($matches[0])));

        Filament::setCurrentPanel('employee');
        $employee = (string) $this->actingAs($administrator)->get('/requests')->getContent();

        $this->assertStringNotContainsString(
            'wire:poll',
            $employee,
            'The employee panel asked the server to be called back. It is a phone on mobile data with the page open for a shift.',
        );
    }

    #[Test]
    public function neither_bell_costs_a_second_round_trip_to_appear(): void
    {
        foreach (['admin', 'employee'] as $panel) {
            $this->assertFalse(
                Filament::getPanel($panel)->hasLazyLoadedDatabaseNotifications(),
                "The {$panel} bell is lazily loaded, so every page load fetches it in a second request.",
            );
        }
    }

    #[Test]
    public function filing_one_request_costs_one_insert_per_administrator_and_nothing_else(): void
    {
        $employee = $this->makeEmployee('sara@company.test');

        foreach (range(1, 2) as $index) {
            $this->makeAdmin("small{$index}@company.test");
        }

        $withTwo = $this->queryCount(fn () => $this->fileCorrection($employee, '2026-09-08'));

        foreach (range(3, 5) as $index) {
            $this->makeAdmin("large{$index}@company.test");
        }

        $withFive = $this->queryCount(fn () => $this->fileCorrection($employee, '2026-09-07'));

        // Three more administrators, three more rows written, and not one
        // more question asked: the audience is a single query whatever its
        // size, and the requester's name is read once and copied into every
        // row rather than looked up per recipient.
        $this->assertSame(
            3,
            $withFive - $withTwo,
            "Filing a request cost {$withTwo} queries with two administrators and {$withFive} with five: something is querying per recipient.",
        );
    }

    #[Test]
    public function opening_the_bell_costs_the_same_whatever_it_holds(): void
    {
        Filament::setCurrentPanel('admin');

        $administrator = $this->makeAdmin('first@company.test');
        $employee = $this->makeEmployee('sara@company.test');

        $this->actingAs($administrator);

        $this->fileCorrection($employee, '2026-09-08');
        $withOne = $this->queryCount(fn () => Livewire::test(RequestNotices::class)->assertOk());

        foreach (['2026-09-07', '2026-09-06', '2026-09-05', '2026-09-04'] as $day) {
            $this->fileCorrection($employee, $day);
        }

        $withFive = $this->queryCount(fn () => Livewire::test(RequestNotices::class)->assertOk());

        // Every line is composed from the row it is stored in - the name, the
        // day and the note are all in the payload - so nothing in the list
        // reaches back to `users`, `attendance_corrections` or anywhere else.
        // A bell that did would run a query per line, fifty at a time, on
        // every poll.
        $this->assertSame(
            $withOne,
            $withFive,
            "The bell ran {$withOne} queries for one notice and {$withFive} for five: a line is being resolved with a query.",
        );
    }
}
