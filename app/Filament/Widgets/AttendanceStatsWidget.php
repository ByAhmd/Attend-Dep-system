<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AttendanceStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceDashboardMetrics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The five figures on the dashboard. The counting is the service's; this
 * class only decides the order, the icon, the colour and where a click
 * goes.
 *
 * Order is the argument. Filament lays five stats out three to a row, and
 * the break between the rows is the break between two questions: the first
 * row answers "what is happening today", starting with the one figure an
 * administrator opens this page for in the morning - who is inside right
 * now - and the second row answers "who is on the books at all".
 *
 * Colour is spent rather than sprinkled, and only three are used:
 *
 *   success - people inside the area right now, the single live figure;
 *   primary - today's arrivals, the day's activity;
 *   gray    - a settled fact that asks nobody to do anything.
 *
 * The two colours that carry a verdict elsewhere in this product - amber
 * for a missing check-out, red for a refused attempt - are deliberately
 * absent here, so nothing on the dashboard can be mistaken for one. Where
 * the figure is not a verdict at all, the colour is gray and the icon does
 * the identifying.
 *
 * The icons are the panel's shared vocabulary: a pin is the place, a tray
 * with an arrow into it is an arrival and out of it a departure. Both
 * arrows are vertical on purpose - a horizontal one would point the wrong
 * way the moment the interface is read right to left.
 *
 * There are no sparklines. A trend line needs a history the metrics
 * service does not count, and counting one would be a sixth figure.
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
     * And never asked for again. See RequestsQueueWidget for the arithmetic:
     * Filament's default re-fetches a stats widget every five seconds, and
     * "who is inside right now" is a figure an administrator reads when they
     * open the page, not one they sit and watch.
     */
    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $metrics = app(AttendanceDashboardMetrics::class);
        $today = app(AttendanceCalendar::class)->today()->toDateString();

        return [
            // Today, in the order the morning is read.
            Stat::make(__('dashboard.stats.currently_checked_in'), (string) $metrics->currentlyCheckedIn())
                ->description(__('dashboard.stats.currently_checked_in_hint'))
                ->icon(Heroicon::OutlinedMapPin)
                ->color('success')
                ->url(self::sessions(['status' => ['value' => AttendanceStatus::CheckedIn->value]])),

            Stat::make(__('dashboard.stats.checked_in_today'), (string) $metrics->checkedInToday())
                ->description(__('dashboard.stats.checked_in_today_hint'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->url(self::sessions(['date' => ['date' => $today]])),

            Stat::make(__('dashboard.stats.checked_out_today'), (string) $metrics->checkedOutToday())
                ->description(__('dashboard.stats.checked_out_today_hint'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(self::sessions([
                    'date' => ['date' => $today],
                    'status' => ['value' => AttendanceStatus::CheckedOut->value],
                ])),

            // The roster underneath it, which changes in months, not hours.
            Stat::make(__('dashboard.stats.employees_active'), (string) $metrics->employeesActive())
                ->description(__('dashboard.stats.employees_active_hint'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('gray')
                ->url(EmployeeResource::getUrl('index', [
                    'filters' => ['status' => ['value' => UserStatus::Active->value]],
                ])),

            Stat::make(__('dashboard.stats.employees_total'), (string) $metrics->employeesTotal())
                ->description(__('dashboard.stats.employees_total_hint'))
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->url(EmployeeResource::getUrl('index')),
        ];
    }

    /**
     * The attendance list, already narrowed to the rows the figure beside
     * the link was counted from.
     *
     * Three figures pointed at the same unfiltered list until now, so a
     * number that promised a slice - who is inside right now - delivered
     * every session ever recorded and left the reader to find that slice
     * themselves. `filters` is the query-string name Filament binds a list
     * page's table filters to, so the destination arrives with its filter
     * bar filled in and a cross to clear it: the reader can see what they
     * were shown and widen it in one click. Every key here names a filter
     * defined on AttendancesTable, and one that ever stopped existing would
     * be ignored rather than break the link.
     *
     * @param  array<string, array<string, string>>  $filters
     */
    private static function sessions(array $filters): string
    {
        return AttendanceResource::getUrl('index', ['filters' => $filters]);
    }
}
