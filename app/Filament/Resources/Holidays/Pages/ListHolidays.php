<?php

declare(strict_types=1);

namespace App\Filament\Resources\Holidays\Pages;

use App\Filament\Resources\Holidays\HolidayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListHolidays extends ListRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * What a holiday does - and pointedly what it does not - said above
     * the list. An administrator will reasonably wonder whether entering
     * one blocks check-ins on those days. It does not, and the cheapest
     * place to say so is here.
     */
    public function getSubheading(): string
    {
        return __('holidays.pages.list.subheading');
    }
}
