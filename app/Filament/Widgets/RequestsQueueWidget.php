<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\RequestStatus;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Services\Requests\RequestQueueMetrics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is waiting for an answer, above the five figures that describe the
 * day.
 *
 * A separate widget rather than a sixth stat on AttendanceStatsWidget,
 * because that widget's five figures are one argument laid out three to a
 * row and each of them links to the rows it counted - one figure cannot
 * link to two lists, and these two are two different queues.
 *
 * Both are shown even at nought, grey when they are. A figure that appears
 * only when it is bad teaches a reader to distrust its absence: after a
 * month of an empty dashboard, nothing on the screen distinguishes "no
 * requests" from "the widget broke".
 *
 * Amber and not red when there is work: a pending request is somebody
 * waiting, not something wrong. Red on this panel means a refused attempt.
 */
final class RequestsQueueWidget extends StatsOverviewWidget
{
    /**
     * Above the day's figures. The queues are the only thing on this page
     * that asks the reader to do something.
     */
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the dashboard, not fetched afterwards. Two indexed
     * counts cost far less than the second HTTP request deferring them
     * would.
     */
    protected static bool $isLazy = false;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $metrics = app(RequestQueueMetrics::class);

        $corrections = $metrics->pendingCorrections();
        $leave = $metrics->pendingLeave();

        // `filters` is the query-string name Filament binds a list page's
        // table filters to, so each figure's destination arrives with its
        // filter bar filled in and a cross to clear it: the reader sees
        // exactly the slice the number described, and can widen it in one
        // click.
        $pending = ['filters' => ['status' => ['value' => RequestStatus::Pending->value]]];

        return [
            Stat::make(__('dashboard.stats.pending_corrections'), (string) $corrections)
                ->description(__('dashboard.stats.pending_corrections_hint'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color($corrections > 0 ? 'warning' : 'gray')
                ->url(AttendanceCorrectionResource::getUrl('index', $pending)),

            Stat::make(__('dashboard.stats.pending_leave'), (string) $leave)
                ->description(__('dashboard.stats.pending_leave_hint'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color($leave > 0 ? 'warning' : 'gray')
                ->url(LeaveRequestResource::getUrl('index', $pending)),
        ];
    }
}
