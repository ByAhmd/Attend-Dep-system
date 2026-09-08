<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Resources\Pages\ListRecords;

final class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * A queue, oldest wait first - the opposite of every log in this panel,
     * and therefore worth saying out loud above it.
     */
    public function getSubheading(): string
    {
        return __('leave.pages.list.subheading');
    }
}
