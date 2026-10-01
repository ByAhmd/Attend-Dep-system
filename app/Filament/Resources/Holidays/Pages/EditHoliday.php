<?php

declare(strict_types=1);

namespace App\Filament\Resources\Holidays\Pages;

use App\Filament\Resources\Holidays\HolidayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditHoliday extends EditRecord
{
    protected static string $resource = HolidayResource::class;

    /**
     * The same delete the row menu offers: somebody who opened a holiday
     * to fix its dates is the person most likely to find it should not
     * exist at all. Stock DeleteAction, because unlike a job title nothing
     * references a holiday and there is no second rule to explain.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('holidays.actions.delete')),
        ];
    }
}
