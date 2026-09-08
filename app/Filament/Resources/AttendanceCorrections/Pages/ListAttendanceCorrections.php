<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceCorrections\Pages;

use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use Filament\Resources\Pages\ListRecords;

final class ListAttendanceCorrections extends ListRecords
{
    protected static string $resource = AttendanceCorrectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Says what the list is and, in three words, how it is ordered.
     *
     * Every other log in this panel is newest first. This one is oldest
     * first, because it is a queue rather than a record, and a reader who
     * did not know that would think the newest requests had gone missing.
     */
    public function getSubheading(): string
    {
        return __('corrections.pages.list.subheading');
    }
}
