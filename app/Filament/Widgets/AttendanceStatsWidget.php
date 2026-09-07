<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Services\Attendance\AttendanceDashboardMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The five figures on the dashboard. The counting is the service's; this
 * class only decides the label, the colour and where a click goes.
 */
final class AttendanceStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the dashboard, not fetched afterwards.
     *
     * Deferred by default, which meant the dashboard arrived with no figures
     * on it and then asked the server again. The five counts are indexed and
     * trivial, so the extra request cost more than the work it deferred.
     */
    protected static bool $isLazy = false;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $metrics = app(AttendanceDashboardMetrics::class);

        return [
            Stat::make(__('dashboard.stats.employees_total'), (string) $metrics->employeesTotal())
                ->description(__('dashboard.stats.employees_total_hint'))
                ->color('primary')
                ->url(EmployeeResource::getUrl('index')),

            Stat::make(__('dashboard.stats.employees_active'), (string) $metrics->employeesActive())
                ->description(__('dashboard.stats.employees_active_hint'))
                ->color('success')
                ->url(EmployeeResource::getUrl('index')),

            Stat::make(__('dashboard.stats.checked_in_today'), (string) $metrics->checkedInToday())
                ->description(__('dashboard.stats.checked_in_today_hint'))
                ->color('info')
                ->url(AttendanceResource::getUrl('index')),

            Stat::make(__('dashboard.stats.checked_out_today'), (string) $metrics->checkedOutToday())
                ->description(__('dashboard.stats.checked_out_today_hint'))
                ->color('gray')
                ->url(AttendanceResource::getUrl('index')),

            Stat::make(__('dashboard.stats.currently_checked_in'), (string) $metrics->currentlyCheckedIn())
                ->description(__('dashboard.stats.currently_checked_in_hint'))
                ->color('warning')
                ->url(AttendanceResource::getUrl('index')),
        ];
    }
}
