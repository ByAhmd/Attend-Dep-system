<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\UserStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Services\Attendance\AttendanceCalendar;
use Closure;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The admin dashboard and its five figures.
 *
 * The fixture is chosen so every figure differs from its neighbours:
 * three employees (one inactive), two records today (one still open),
 * and a record from yesterday that must count nowhere.
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
        $widget = Livewire::test(AttendanceStatsWidget::class)->instance();

        /** @var array<int, Stat> $stats */
        $stats = Closure::bind(fn (): array => $this->getStats(), $widget, AttendanceStatsWidget::class)();

        $byLabel = [];

        foreach ($stats as $stat) {
            $byLabel[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $byLabel;
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

        $this->assertSame([
            __('dashboard.stats.employees_total') => '3',
            __('dashboard.stats.employees_active') => '2',
            __('dashboard.stats.checked_in_today') => '2',
            __('dashboard.stats.checked_out_today') => '1',
            __('dashboard.stats.currently_checked_in') => '1',
        ], $this->statsByLabel());
    }

    #[Test]
    public function an_empty_system_reports_zero_everywhere(): void
    {
        $this->assertSame(['0', '0', '0', '0', '0'], array_values($this->statsByLabel()));
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
}
