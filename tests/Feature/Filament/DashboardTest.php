<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Filament\Widgets\RequestsQueueWidget;
use App\Services\Attendance\AttendanceCalendar;
use Closure;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The admin dashboard: the two queue figures above, and the five that
 * describe the day below.
 *
 * The fixture is chosen so every figure differs from its neighbours:
 * three employees (one inactive), two records today (one still open),
 * and a record from yesterday that must count nowhere.
 *
 * Since a day may hold several sessions per person, the three attendance
 * figures count PEOPLE. A person who went out for lunch and came back is
 * one person checked in, not three.
 *
 * The order of the five is part of what the dashboard says, so it is
 * asserted rather than assumed: today first, starting with who is inside
 * right now, and the roster after it. Filament lays five stats out three
 * to a row, so that order is also the break between the two rows.
 */
final class DashboardTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs($this->makeAdmin());
    }

    private function seedTodaysAttendance(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');
        $this->makeEmployee('former@company.test', UserStatus::Inactive);

        $this->checkedIn($sara);
        $this->checkedOut($omar);
        $this->checkedOut($sara, app(AttendanceCalendar::class)->today()->subDay());
    }

    /**
     * The stat row as label => value, read through the widget's own
     * getStats() so the assertion covers the wiring to the service, not a
     * copy of its arithmetic.
     *
     * @return array<string, string>
     */
    private function statsByLabel(): array
    {
        return $this->readStats(AttendanceStatsWidget::class);
    }

    /**
     * The queue row as label => value, read the same way.
     *
     * @return array<string, string>
     */
    private function queueStatsByLabel(): array
    {
        return $this->readStats(RequestsQueueWidget::class);
    }

    /**
     * A stats widget's own getStats(), so the assertion covers the wiring
     * to the service rather than a copy of its arithmetic.
     *
     * @param  class-string<StatsOverviewWidget>  $widget
     * @return array<string, string>
     */
    private function readStats(string $widget): array
    {
        $instance = Livewire::test($widget)->instance();

        /** @var array<int, Stat> $stats */
        $stats = Closure::bind(fn (): array => $this->getStats(), $instance, $widget)();

        $byLabel = [];

        foreach ($stats as $stat) {
            $byLabel[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $byLabel;
    }

    /**
     * The Stat objects of a widget, for the assertions that need more than
     * the label and the value.
     *
     * @param  class-string<StatsOverviewWidget>  $widget
     * @return array<int, Stat>
     */
    private function readStatObjects(string $widget): array
    {
        $instance = Livewire::test($widget)->instance();

        /** @var array<int, Stat> $stats */
        $stats = Closure::bind(fn (): array => $this->getStats(), $instance, $widget)();

        return $stats;
    }

    #[Test]
    public function the_dashboard_and_its_widget_render(): void
    {
        $this->seedTodaysAttendance();

        Livewire::test(Dashboard::class)->assertOk();

        Livewire::test(AttendanceStatsWidget::class)
            ->assertOk()
            ->assertSee(__('dashboard.stats.employees_total'))
            ->assertSee(__('dashboard.stats.employees_active'))
            ->assertSee(__('dashboard.stats.checked_in_today'))
            ->assertSee(__('dashboard.stats.checked_out_today'))
            ->assertSee(__('dashboard.stats.currently_checked_in'));
    }

    #[Test]
    public function each_stat_equals_the_seeded_counts(): void
    {
        $this->seedTodaysAttendance();

        // assertSame compares key order too, so this pins the briefing
        // order as well as the arithmetic.
        $this->assertSame([
            __('dashboard.stats.currently_checked_in') => '1',
            __('dashboard.stats.checked_in_today') => '2',
            __('dashboard.stats.checked_out_today') => '1',
            __('dashboard.stats.employees_active') => '2',
            __('dashboard.stats.employees_total') => '3',
        ], $this->statsByLabel());
    }

    #[Test]
    public function an_empty_system_reports_zero_everywhere(): void
    {
        $this->assertSame(['0', '0', '0', '0', '0'], array_values($this->statsByLabel()));
    }

    #[Test]
    public function the_attendance_figures_count_people_and_not_sessions(): void
    {
        // One person, three sessions, currently inside. Counting rows would
        // report three people checked in today and two who have left.
        $sara = $this->makeEmployee('sara@company.test');

        $this->attendanceSession($sara, '08:00', '12:00');
        $this->attendanceSession($sara, '13:00', '17:00');
        $this->attendanceSession($sara, '18:00');

        $this->assertSame([
            __('dashboard.stats.currently_checked_in') => '1',
            __('dashboard.stats.checked_in_today') => '1',
            __('dashboard.stats.checked_out_today') => '1',
            __('dashboard.stats.employees_active') => '1',
            __('dashboard.stats.employees_total') => '1',
        ], $this->statsByLabel());
    }

    #[Test]
    public function someone_who_left_for_good_is_not_counted_as_present(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        // Sara went out for lunch and came back; Omar has gone home.
        $this->attendanceSession($sara, '08:00', '12:00');
        $this->attendanceSession($sara, '13:00');
        $this->attendanceSession($omar, '09:00', '16:00');

        $stats = $this->statsByLabel();

        $this->assertSame('2', $stats[__('dashboard.stats.checked_in_today')]);
        $this->assertSame('2', $stats[__('dashboard.stats.checked_out_today')]);
        $this->assertSame('1', $stats[__('dashboard.stats.currently_checked_in')]);
    }

    #[Test]
    public function yesterdays_forgotten_check_out_is_not_somebody_still_at_their_desk(): void
    {
        $sara = $this->makeEmployee('sara@company.test');

        $this->checkedIn($sara, app(AttendanceCalendar::class)->today()->subDay());

        $stats = $this->statsByLabel();

        $this->assertSame('0', $stats[__('dashboard.stats.checked_in_today')]);
        $this->assertSame('0', $stats[__('dashboard.stats.checked_out_today')]);
        $this->assertSame('0', $stats[__('dashboard.stats.currently_checked_in')]);
    }

    #[Test]
    public function the_shortcuts_lead_to_the_three_resources(): void
    {
        Livewire::test(Dashboard::class)
            ->assertActionHasUrl('employees', EmployeeResource::getUrl('index'))
            ->assertActionHasUrl('attendance', AttendanceResource::getUrl('index'))
            ->assertActionHasUrl('settings', AttendanceSettingResource::getUrl('index'));
    }

    #[Test]
    public function the_dashboard_answers_the_panel_root_over_http(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee(__('dashboard.title'))
            ->assertSee(__('dashboard.stats.employees_total'));
    }

    #[Test]
    public function the_queue_widget_is_on_the_dashboard_above_the_days_figures(): void
    {
        $this->assertSame(
            [RequestsQueueWidget::class, AttendanceStatsWidget::class],
            (new Dashboard)->getWidgets(),
        );

        Livewire::test(RequestsQueueWidget::class)
            ->assertOk()
            ->assertSee(__('dashboard.stats.pending_corrections'))
            ->assertSee(__('dashboard.stats.pending_leave'));
    }

    #[Test]
    public function the_two_queue_figures_count_what_is_waiting_for_a_decision(): void
    {
        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $this->correctionRequest($sara);
        $this->correctionRequest($omar);
        $this->leaveRequest($sara);

        $this->assertSame([
            __('dashboard.stats.pending_corrections') => '2',
            __('dashboard.stats.pending_leave') => '1',
        ], $this->queueStatsByLabel());
    }

    #[Test]
    public function an_empty_queue_still_shows_its_figure_and_shows_it_grey(): void
    {
        // A figure that appeared only when it was bad would teach the
        // reader to distrust its absence.
        $stats = $this->readStatObjects(RequestsQueueWidget::class);

        $this->assertCount(2, $stats);

        foreach ($stats as $stat) {
            $this->assertSame('0', (string) $stat->getValue());
            $this->assertSame('gray', $stat->getColor());
        }
    }

    #[Test]
    public function a_waiting_queue_is_amber(): void
    {
        $this->correctionRequest($this->makeEmployee('sara@company.test'));

        $stats = $this->readStatObjects(RequestsQueueWidget::class);

        $this->assertSame('warning', $stats[0]->getColor());
        $this->assertSame('gray', $stats[1]->getColor());
    }

    #[Test]
    public function each_queue_figure_links_to_its_own_list_already_filtered_to_pending(): void
    {
        $pending = ['filters' => ['status' => ['value' => RequestStatus::Pending->value]]];

        $stats = $this->readStatObjects(RequestsQueueWidget::class);

        $this->assertSame(AttendanceCorrectionResource::getUrl('index', $pending), $stats[0]->getUrl());
        $this->assertSame(LeaveRequestResource::getUrl('index', $pending), $stats[1]->getUrl());
    }
}
