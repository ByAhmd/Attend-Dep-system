<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Holiday;
use App\Models\User;
use App\Services\Attendance\Absentees;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\WorkingCalendar;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Who was expected today and has not checked in, above the late list:
 * somebody missing outranks somebody late.
 *
 * The wording is the feature. The heading says "have not checked in",
 * never "absent", because the absence of a record is the whole of what
 * this system can prove - the person may be in the building with a dead
 * phone. The description says who is deliberately not listed (approved
 * leave), and on a weekend or holiday the empty state names the day
 * instead of congratulating anybody: an empty list must never look the
 * same for "everyone is here" and "nobody was expected".
 *
 * No polling, like everything else on this dashboard.
 */
final class AbsenteesWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the dashboard, not fetched afterwards, for the same
     * reason as every widget beside it.
     */
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $today = app(AttendanceCalendar::class)->today();
        $calendar = app(WorkingCalendar::class);
        $isWorkingDay = $calendar->isWorkingDay($today);

        return $table
            ->query(app(Absentees::class)->queryForToday()->with('jobTitle'))
            ->heading(__('dashboard.absent.heading'))
            ->description(__('dashboard.absent.description'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('attendance.fields.employee'))
                    ->weight(FontWeight::SemiBold)
                    ->grow(),

                TextColumn::make('job_title')
                    ->label(__('dashboard.absent.job_title'))
                    ->state(fn (User $record): ?string => $record->jobTitle?->displayName())
                    ->placeholder('—')
                    ->color('gray'),
            ])
            ->paginated(false)
            ->stackedOnMobile()
            ->emptyStateIcon($isWorkingDay ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading($isWorkingDay
                ? __('dashboard.absent.empty_heading')
                : __('dashboard.absent.non_working_heading', ['day' => self::nonWorkingName($calendar)]))
            ->emptyStateDescription($isWorkingDay
                ? __('dashboard.absent.empty_description')
                : __('dashboard.absent.non_working_description'));
    }

    /**
     * What today is instead of a working day: the holiday's own name, or
     * the weekday's, so "nobody was expected" arrives with its reason.
     */
    private static function nonWorkingName(WorkingCalendar $calendar): string
    {
        $today = app(AttendanceCalendar::class)->today();
        $holiday = $calendar->holidayCovering($today);

        if ($holiday instanceof Holiday) {
            return $holiday->displayName();
        }

        return $today->locale(app()->getLocale())->isoFormat('dddd');
    }
}
