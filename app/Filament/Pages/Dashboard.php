<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Filament\Widgets\RequestsQueueWidget;
use App\Services\Attendance\AttendanceCalendar;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * The admin landing page: what is waiting for an answer, today's five
 * figures, and a shortcut to each of the three things an administrator
 * comes here to do.
 */
final class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    // The same glyph filled in, so the entry the reader is standing on is
    // legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Home;

    public static function getNavigationLabel(): string
    {
        return __('dashboard.title');
    }

    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    /**
     * A briefing says which day it is briefing about.
     *
     * The date comes from AttendanceCalendar - the same clock the figures
     * below were counted against - rather than from the reader's device,
     * because the whole product turns on whose "today" is meant. Saying so
     * in the subheading is the cheapest way to make "0 checked in" legible
     * at 00:30 on a Friday.
     *
     * The format carries no punctuation of its own: the sentence around it
     * supplies that, and it has to be an Arabic comma in Arabic.
     */
    public function getSubheading(): string
    {
        return __('dashboard.subheading', [
            'date' => app(AttendanceCalendar::class)
                ->today()
                ->locale(app()->getLocale())
                ->isoFormat('dddd D MMMM YYYY'),
        ]);
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            // The queues come first: they are the only thing on this page
            // that asks the reader to do something today.
            RequestsQueueWidget::class,
            AttendanceStatsWidget::class,
        ];
    }

    /**
     * Shortcuts, not calls to action.
     *
     * All three destinations are in the sidebar as well, so these are a
     * convenience and are dressed as one: neutral buttons carrying the same
     * icon the sidebar entry does. Filled brand-coloured buttons would have
     * been the loudest thing on a page whose whole job is to let five
     * numbers be read.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('attendance')
                ->label(__('dashboard.shortcuts.attendance'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->url(AttendanceResource::getUrl('index')),

            Action::make('employees')
                ->label(__('dashboard.shortcuts.employees'))
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->url(EmployeeResource::getUrl('index')),

            Action::make('settings')
                ->label(__('dashboard.shortcuts.settings'))
                ->icon(Heroicon::OutlinedMapPin)
                ->color('gray')
                ->url(AttendanceSettingResource::getUrl('index')),
        ];
    }
}
