<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\ResetPassword;
use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Providers\Filament\Concerns\ConfiguresPanel;
use App\Support\Filament\PanelAccess;
use App\Support\Filament\PanelSwitchMenuItems;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;

/**
 * The employee panel at the site root: sign in, then one screen - today's
 * status, Check In, Check Out, history.
 *
 * A Filament panel rather than hand-written Blade because it supplies the
 * login page, session handling, CSRF, rate limiting and a mobile layout
 * with nothing to maintain. The sidebar is switched off: there is one page
 * and the top bar holds the sign-out.
 *
 * Password reset lives here and only here. It is the screen an invited
 * employee lands on to choose their first password, so the invitation link
 * has somewhere to point; the admin panel keeps no reset flow, because
 * administrators are created with app:create-admin.
 */
final class EmployeePanelProvider extends PanelProvider
{
    use ConfiguresPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->applyPresentation(
            $panel
                ->default()
                ->id(PanelAccess::EMPLOYEE_PANEL_ID)
                ->path('')
                ->login()
                // Filament's request screen, but our own reset screen: the
                // stock one refuses an account that cannot enter the panel,
                // which is precisely an account still waiting to be invited.
                ->passwordReset(resetAction: ResetPassword::class)
                // The only way to /admin from here. An administrator who
                // signs in at the site root lands on this screen, and
                // without this entry the administration panel is reachable
                // only by typing its address.
                ->userMenuItems(PanelSwitchMenuItems::toAdminPanel())
                ->navigation(false)
                // A phone-width card even on a desktop: the screen is two
                // buttons and a status, and stretching it adds nothing.
                ->maxContentWidth(Width::Medium)
                ->pages([Attendance::class])
                ->widgets([AttendanceHistoryWidget::class]),
        );
    }
}
