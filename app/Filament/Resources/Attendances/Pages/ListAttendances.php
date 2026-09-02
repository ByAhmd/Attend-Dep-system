<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use Filament\Resources\Pages\ListRecords;

final class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): string
    {
        return __('attendance.pages.list.subheading');
    }
}
