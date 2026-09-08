<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\Requests;
use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Filament\Employee\Widgets\MyCorrectionRequestsWidget;
use App\Filament\Employee\Widgets\MyLeaveRequestsWidget;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Filament\Widgets\RequestsQueueWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What a page costs the server - and what it costs the reader - guarded so
 * neither can quietly grow.
 *
 * Three things go wrong in a Filament application and none of them shows up
 * in a functional test. A query inside a column closure turns one page into
 * one query per row. A widget left on Filament's default deferred loading
 * costs a whole extra HTTP request after the page has painted - which on a
 * phone, on mobile data, is the dominant cost of opening the screen, far
 * more than the query it defers. And a stock default can put a request to
 * somebody else's server on every single page load, which is slower than
 * anything measured here, fails on a network that blocks the host, and in
 * this product carries an employee's initials off the premises to do it.
 */
final class PageCostTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

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

    private function sessionsFor(User $employee, int $count, int $startHour = 6): void
    {
        for ($i = 0; $i < $count; $i++) {
            $hour = $startHour + $i;

            $this->attendanceSession($employee, sprintf('%02d:00', $hour), sprintf('%02d:30', $hour));
        }
    }

    /**
     * Pending correction requests on distinct days, because at most one may
     * be pending per employee per day.
     */
    private function correctionsFor(User $employee, int $count): void
    {
        for ($day = 1; $day <= $count; $day++) {
            $this->correctionRequest($employee, on: now()->subDays($day));
        }
    }

    #[Test]
    public function every_widget_renders_with_its_page_rather_than_asking_again(): void
    {
        // Filament defers widgets by default. Ours are indexed lookups of a
        // handful of rows and a few counts, so deferring them buys nothing
        // and costs a second round trip on every single page load.
        $this->assertFalse(
            AttendanceHistoryWidget::isLazy(),
            'The history widget must render with the employee page, not in a second request.',
        );

        $this->assertFalse(
            AttendanceStatsWidget::isLazy(),
            'The dashboard stats must render with the dashboard, not in a second request.',
        );

        $this->assertFalse(
            RequestsQueueWidget::isLazy(),
            'The queue figures must render with the dashboard, not in a second request.',
        );

        $this->assertFalse(
            MyCorrectionRequestsWidget::isLazy(),
            'The correction requests table must render with the requests page, not in a second request.',
        );

        $this->assertFalse(
            MyLeaveRequestsWidget::isLazy(),
            'The leave requests table must render with the requests page, not in a second request.',
        );
    }

    #[Test]
    public function the_employee_screen_costs_the_same_whatever_the_day_held(): void
    {
        Filament::setCurrentPanel('employee');
        $this->configureCompanyLocation();

        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        $this->sessionsFor($employee, 1);
        $withOne = $this->queryCount(fn () => Livewire::test(Attendance::class)->assertOk());

        $this->sessionsFor($employee, 9, startHour: 8);
        $withTen = $this->queryCount(fn () => Livewire::test(Attendance::class)->assertOk());

        $this->assertSame(
            $withOne,
            $withTen,
            "The employee screen ran {$withOne} queries for one session and {$withTen} for ten: a query is being run per session.",
        );
    }

    #[Test]
    public function the_attendance_list_costs_the_same_whatever_it_shows(): void
    {
        Filament::setCurrentPanel('admin');
        $this->configureCompanyLocation();
        $this->actingAs($this->makeAdmin());

        foreach (range(1, 3) as $i) {
            $this->sessionsFor($this->makeEmployee("first{$i}@company.test"), 2);
        }

        $small = $this->queryCount(fn () => Livewire::test(ListAttendances::class)->assertOk());

        foreach (range(1, 9) as $i) {
            $this->sessionsFor($this->makeEmployee("more{$i}@company.test"), 2);
        }

        $large = $this->queryCount(fn () => Livewire::test(ListAttendances::class)->assertOk());

        // The employee name is eager-loaded and the status is derived in
        // PHP, so four times the rows must cost the same as the first six.
        $this->assertSame(
            $small,
            $large,
            "The attendance list ran {$small} queries for six sessions and {$large} for twenty-four: the employee relation or a column is querying per row.",
        );
    }

    #[Test]
    public function the_requests_screen_costs_the_same_whatever_the_employee_has_asked_for(): void
    {
        Filament::setCurrentPanel('employee');

        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        $this->correctionRequest($employee, on: now()->subDays(20));
        $withOne = $this->queryCount(fn () => Livewire::test(Requests::class)->assertOk());

        foreach (range(1, 9) as $day) {
            $this->correctionRequest($employee, on: now()->subDays($day));
        }

        $withTen = $this->queryCount(fn () => Livewire::test(Requests::class)->assertOk());

        // The reason and the status are enum casts on the loaded row and
        // the requested range is composed in PHP, so ten requests must cost
        // exactly what one did.
        $this->assertSame(
            $withOne,
            $withTen,
            "The requests screen ran {$withOne} queries for one request and {$withTen} for ten: a query is being run per row.",
        );
    }

    #[Test]
    public function the_correction_queue_costs_the_same_whatever_it_shows(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());

        foreach (range(1, 3) as $i) {
            $this->correctionsFor($this->makeEmployee("first{$i}@company.test"), 2);
        }

        $small = $this->queryCount(fn () => Livewire::test(ListAttendanceCorrections::class)->assertOk());

        foreach (range(1, 9) as $i) {
            $this->correctionsFor($this->makeEmployee("more{$i}@company.test"), 2);
        }

        $large = $this->queryCount(fn () => Livewire::test(ListAttendanceCorrections::class)->assertOk());

        $this->assertSame(
            $small,
            $large,
            "The correction queue ran {$small} queries for six requests and {$large} for twenty-four: the employee relation or a column is querying per row.",
        );
    }

    #[Test]
    public function the_dashboard_answers_with_its_figures_already_counted(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());

        $this->sessionsFor($this->makeEmployee(), 2);

        // Eager widgets mean the counting happens in the page's own request.
        // Zero here would mean the figures are being fetched afterwards.
        $this->assertGreaterThan(
            0,
            $this->queryCount(fn () => Livewire::test(Dashboard::class)->assertOk()),
            'The dashboard rendered without counting anything, so its figures are being fetched in a second request.',
        );
    }

    #[Test]
    public function the_avatar_is_drawn_here_rather_than_fetched_from_somebody_else(): void
    {
        Filament::setCurrentPanel('employee');

        $employee = $this->makeEmployee();
        $employee->forceFill(['name' => 'Sara Alharbi'])->save();

        $avatar = Filament::getUserAvatarUrl($employee);

        // A data: URI is part of the HTML the server already sent, so the
        // browser makes no request for it at all. Filament's own default
        // would be an https://ui-avatars.com/ address here.
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringNotContainsString('ui-avatars', $avatar);

        $svg = (string) base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,')), true);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('S A', $svg);

        // Drawn in the panel's own ramp rather than the near-black disc
        // Filament asks ui-avatars for, which was the one element on the
        // screen outside this product's palette - and invisible against the
        // dark theme's own top bar.
        $this->assertStringContainsString('fill="#6c4cf3"', $svg);
    }

    #[Test]
    public function no_page_an_employee_opens_asks_another_server_for_anything(): void
    {
        $this->configureCompanyLocation();

        $employee = $this->makeEmployee();

        // The sign-in screen and the two screens behind it. Bunny serves
        // the webfont and is declared on the panel on purpose; nothing else
        // on any of these pages may reach off this server, and the avatar
        // is the one that used to.
        foreach (['/login', '/', '/requests'] as $path) {
            $response = $path === '/login'
                ? $this->get($path)
                : $this->actingAs($employee)->get($path);

            $response->assertOk();

            $this->assertStringNotContainsString(
                'ui-avatars.com',
                $response->getContent() ?: '',
                "{$path} still points the reader's browser at ui-avatars.com.",
            );
        }
    }

    #[Test]
    public function a_name_with_no_letters_in_it_still_gets_an_avatar(): void
    {
        Filament::setCurrentPanel('employee');

        $employee = $this->makeEmployee();
        $employee->forceFill(['name' => '???'])->save();

        $avatar = Filament::getUserAvatarUrl($employee);
        $svg = (string) base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,')), true);

        // No initials to draw, so the person glyph rather than a blank disc
        // or a literal question mark, which would read as an error somebody
        // is expected to fix.
        $this->assertStringContainsString('<path', $svg);
        $this->assertStringNotContainsString('<text', $svg);
    }
}
