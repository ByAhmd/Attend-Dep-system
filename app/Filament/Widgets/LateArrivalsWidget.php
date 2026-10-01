<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\LateArrivals;
use App\Support\Attendance\SessionDuration;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Today's late arrivals, under the day's figures: who, when they first
 * checked in, and how much of the working day was already gone.
 *
 * The counting is the service's; this class only decides what a row says.
 * The lateness figure is measured from the START of the working day, not
 * from the end of the grace - the grace decides whether a name appears
 * here at all, and once it does, the figure is the working time actually
 * missed. The heading's description says so, because a reader comparing
 * "arrived 09:45" with "late 45 m" must find the arithmetic stated rather
 * than have to reverse-engineer it.
 *
 * An empty list is a good morning and is shown as one, not hidden: a
 * table that appeared only when somebody was late would teach the reader
 * to distrust its absence, exactly as the queue figures argue above.
 *
 * No polling, like everything else on this dashboard: who was late today
 * is settled by mid-morning and read once.
 */
final class LateArrivalsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the dashboard, not fetched afterwards, for the same
     * reason as the stats beside it: the query is one indexed scan of
     * today's rows, and the extra request would cost more than it defers.
     */
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $workingHours = AttendanceSetting::current()->workingHours();
        $today = app(AttendanceCalendar::class)->today();

        return $table
            ->query(app(LateArrivals::class)->queryForToday())
            ->heading(__('dashboard.late.heading'))
            ->description(__('dashboard.late.description', [
                'threshold' => $workingHours->lateThresholdOn($today)->format('H:i'),
                'start' => $workingHours->startOn($today)->format('H:i'),
            ]))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('attendance.fields.employee'))
                    ->weight(FontWeight::SemiBold)
                    ->grow(),

                TextColumn::make('check_in_at')
                    ->label(__('dashboard.late.first_check_in'))
                    ->time('H:i')
                    ->fontFamily(FontFamily::Mono),

                // The one figure this table exists for, semibold like the
                // duration on the attendance list.
                TextColumn::make('lateness')
                    ->label(__('dashboard.late.lateness'))
                    ->state(fn (Attendance $record): string => SessionDuration::format(
                        $workingHours->latenessSeconds($record->check_in_at),
                    ))
                    ->extraAttributes(['class' => 'fi-numeric'])
                    ->weight(FontWeight::SemiBold),
            ])
            // The row opens the person's day on the attendance list, the
            // same way the figures above open the slice they were counted
            // from: the number and the rows behind it must agree.
            ->recordUrl(fn (Attendance $record): string => AttendanceResource::getUrl('index', [
                'filters' => [
                    'user_id' => ['value' => (string) $record->user_id],
                    'date' => ['date' => $today->toDateString()],
                ],
            ]))
            // Fifteen people cannot produce a second page of late
            // arrivals; a pagination bar under three rows is furniture.
            ->paginated(false)
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('dashboard.late.empty_heading'))
            ->emptyStateDescription(__('dashboard.late.empty_description'));
    }
}
