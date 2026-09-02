<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceRejections\Pages;

use App\Filament\Resources\AttendanceRejections\AttendanceRejectionResource;
use Filament\Resources\Pages\ListRecords;

final class ListAttendanceRejections extends ListRecords
{
    protected static string $resource = AttendanceRejectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): string
    {
        return __('rejections.pages.list.subheading');
    }
}
