<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\NavigationGroup;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\AttendanceRejections\AttendanceRejectionResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\AttendanceSettings\AttendanceSettingResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\JobTitles\JobTitleResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\PresencePings\PresencePingResource;
use App\Filament\Widgets\AttendanceStatsWidget;
use App\Filament\Widgets\RequestsQueueWidget;
use App\Providers\Filament\Concerns\ConfiguresPanel;
use App\Support\Filament\PanelAccess;
use App\Support\Filament\PanelSwitchMenuItems;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The administration panel at /admin: employees and the job titles they may
 * be given, attendance records, the audit trail of rejected attempts, the
 * presence pings recorded during open sessions, the two request queues, and
 * the company location settings.
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
                // An administrator is staff too and checks in like everyone
                // else; this is the only link there is to the screen they do
                // it on.
                ->userMenuItems(PanelSwitchMenuItems::toEmployeePanel())
                // The sidebar is 320px and Filament pins it open from 1024px
                // up, which is where this panel is at its most cramped: a
                // 1024px laptop gives the attendance table 625px to lay six
                // columns and a row action in, and it needs 754 - so the
                // View button ends up off the edge, on the width where the
                // sidebar first appears and takes a third of the screen.
                // Collapsing it to its icon rail hands back 240px, which is
                // more than any of these tables is short of, and Filament
                // remembers the choice per reader.
                ->sidebarCollapsibleOnDesktop()
                // Five minutes, against Filament's default of thirty
                // seconds. A poll is a whole round trip to a server 300ms
                // away, and it re-renders the list as well as the count, so
                // an administrator sitting on the dashboard for a working
                // day costs 960 of them at the default and 96 at this. What
                // that buys is knowing about a request four and a half
                // minutes sooner - a request that arrives twice a week and
                // is answered in a day. The interval is here rather than in
                // the shared trait because it is a statement about this
                // panel's readers: a handful of people, on a desktop, who
                // are the ones who have to act.
                ->databaseNotificationsPolling('300s')
                ->navigationGroups($this->navigationGroups())
                ->resources([
                    EmployeeResource::class,
                    JobTitleResource::class,
                    AttendanceResource::class,
                    AttendanceRejectionResource::class,
                    PresencePingResource::class,
                    AttendanceCorrectionResource::class,
                    LeaveRequestResource::class,
                    AttendanceSettingResource::class,
                ])
                ->pages([
                    Dashboard::class,
                ])
                ->widgets([
                    RequestsQueueWidget::class,
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
