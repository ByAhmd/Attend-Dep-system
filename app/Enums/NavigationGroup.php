<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Admin panel sidebar groups.
 *
 * An enum rather than translated strings: Filament matches a resource to its
 * group by value, and the panel is configured once at boot while resources
 * render per request, so a translated string on both sides stops matching
 * the moment the locale differs. Case order is the sidebar order.
 */
enum NavigationGroup: string implements HasLabel
{
    case Attendance = 'attendance';
    case Requests = 'requests';
    case System = 'system';

    public function getLabel(): string
    {
        return __('navigation.'.$this->value);
    }
}
