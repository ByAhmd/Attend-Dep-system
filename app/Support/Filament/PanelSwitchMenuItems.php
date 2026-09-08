<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;

/**
 * The way from one panel to the other, for the people who belong in both.
 *
 * Every account may enter the employee panel and administrators may enter
 * both, so an administrator has two screens in this product - and, until
 * this existed, no link between them. Signing in at the site root landed an
 * administrator on the attendance screen with nothing on it that mentions
 * /admin, and the administration panel offered nothing that leads back to
 * the screen the administrator uses to record their own attendance. Both
 * were dead ends reachable only by editing the address bar.
 *
 * One item in the user menu on each side, sorted above the theme switcher
 * because it is somewhere to go rather than a preference. The label names
 * the destination, not the act of switching: a menu entry that says where
 * it lands needs no explaining.
 */
final class PanelSwitchMenuItems
{
    /**
     * Shown on the employee panel, and only to an account that may actually
     * enter the administration panel - the same question PanelAccess
     * answers for the request itself, so the menu can never offer a door
     * that the middleware would then close.
     *
     * @return array<string, Action>
     */
    public static function toAdminPanel(): array
    {
        return [
            'adminPanel' => Action::make('adminPanel')
                ->label(fn (): string => (string) __('app.panels.admin'))
                ->icon(Heroicon::OutlinedHome)
                ->sort(-1)
                ->visible(fn (): bool => self::mayEnterAdminPanel())
                ->url(fn (): string => Filament::getPanel(PanelAccess::ADMIN_PANEL_ID)->getUrl()),
        ];
    }

    /**
     * Shown on the administration panel, to everyone on it: an administrator
     * is staff too, and the employee panel is where they check in.
     *
     * @return array<string, Action>
     */
    public static function toEmployeePanel(): array
    {
        return [
            'employeePanel' => Action::make('employeePanel')
                ->label(fn (): string => (string) __('app.panels.employee'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->sort(-1)
                ->url(fn (): string => Filament::getPanel(PanelAccess::EMPLOYEE_PANEL_ID)->getUrl()),
        ];
    }

    private static function mayEnterAdminPanel(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && PanelAccess::canAccess($user, PanelAccess::ADMIN_PANEL_ID);
    }
}
