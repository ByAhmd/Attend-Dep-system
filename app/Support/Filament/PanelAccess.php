<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Models\User;

/**
 * Which panel a user may enter.
 *
 * The employee panel is open to every active account, administrators
 * included - an administrator is staff too and may record attendance. The
 * admin panel is for administrators only. Status is checked first so a
 * deactivated administrator is locked out of both.
 */
final class PanelAccess
{
    public const string ADMIN_PANEL_ID = 'admin';

    public const string EMPLOYEE_PANEL_ID = 'employee';

    public static function canAccess(User $user, string $panelId): bool
    {
        if (! $user->status->canAuthenticate()) {
            return false;
        }

        return match ($panelId) {
            self::ADMIN_PANEL_ID => $user->role->isAdmin(),
            self::EMPLOYEE_PANEL_ID => true,
            default => false,
        };
    }
}
