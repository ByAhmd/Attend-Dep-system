<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Widgets\AttendanceStatsWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;

/**
 * The admin landing page: today's five figures and a shortcut to each of
 * the three things an administrator comes here to do.
 */
final class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return __('dashboard.title');
    }

    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    public function getSubheading(): string
    {
        return __('dashboard.subheading');
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            AttendanceStatsWidget::class,
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('employees')
                ->label(__('dashboard.shortcuts.employees'))
                ->url(EmployeeResource::getUrl('index')),

            Action::make('attendance')
                ->label(__('dashboard.shortcuts.attendance'))
                ->url(AttendanceResource::getUrl('index')),

            Action::make('settings')
                ->label(__('dashboard.shortcuts.settings'))
                ->url(AttendanceSettingResource::getUrl('index')),
        ];
    }
}
