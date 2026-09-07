<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What a page costs the server, guarded so it cannot quietly grow.
 *
 * Two things go wrong in a Filament application and neither shows up in a
 * functional test. A query inside a column closure turns one page into one
 * query per row. And a widget left on Filament's default deferred loading
 * costs a whole extra HTTP request after the page has painted - which on a
 * phone, on mobile data, is the dominant cost of opening the screen, far
 * more than the query it defers.
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

    #[Test]
    public function both_widgets_render_with_their_page_rather_than_asking_again(): void
    {
        // Filament defers widgets by default. Ours are a single indexed
        // lookup and five counts, so deferring them buys nothing and costs
        // a second round trip on every single page load.
        $this->assertFalse(
            AttendanceHistoryWidget::isLazy(),
            'The history widget must render with the employee page, not in a second request.',
        );

        $this->assertFalse(
            AttendanceStatsWidget::isLazy(),
            'The dashboard stats must render with the dashboard, not in a second request.',
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
}
