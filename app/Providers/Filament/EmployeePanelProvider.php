<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Providers\Filament\Concerns\ConfiguresPanel;
use App\Support\Filament\PanelAccess;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The employee panel at the site root: sign in, then one screen - today's
 * status, Check In, Check Out, history.
 *
 * A Filament panel rather than hand-written Blade because it supplies the
 * login page, session handling, CSRF, rate limiting and a mobile layout
 * with nothing to maintain. The sidebar is switched off: there is one page
 * and the top bar holds the sign-out.
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
                ->navigation(false)
                ->pages([])
                ->widgets([]),
        );
    }
}
