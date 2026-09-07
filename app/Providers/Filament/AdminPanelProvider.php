<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\NavigationGroup;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AttendanceRejections\AttendanceRejectionResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\PresencePings\PresencePingResource;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Providers\Filament\Concerns\ConfiguresPanel;
use App\Support\Filament\PanelAccess;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The administration panel at /admin: employees, attendance records, the
 * audit trail of rejected attempts, the presence pings recorded during open
 * sessions, and the company location settings.
 *
 * Resources and pages are listed explicitly rather than discovered, so
 * nothing can land on a panel by being in the wrong directory.
 */
final class AdminPanelProvider extends PanelProvider
{
    use ConfiguresPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->applyPresentation(
            $panel
                ->id(PanelAccess::ADMIN_PANEL_ID)
                ->path('admin')
                ->login()
                ->navigationGroups($this->navigationGroups())
                ->resources([
                    EmployeeResource::class,
                    AttendanceResource::class,
                    AttendanceRejectionResource::class,
                    PresencePingResource::class,
                    AttendanceSettingResource::class,
                ])
                ->pages([
                    Dashboard::class,
                ])
                ->widgets([
                    AttendanceStatsWidget::class,
                ]),
        );
    }

    /**
     * @return array<string, FilamentNavigationGroup>
     */
    private function navigationGroups(): array
    {
        $groups = [];

        foreach (NavigationGroup::cases() as $case) {
            $groups[$case->name] = FilamentNavigationGroup::make()
                ->label(fn (): string => $case->getLabel());
        }

        return $groups;
    }
}
