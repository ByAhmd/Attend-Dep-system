<?php

declare(strict_types=1);

namespace App\Filament\Resources\Holidays\Pages;

use App\Filament\Resources\Holidays\HolidayResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateHoliday extends CreateRecord
{
    protected static string $resource = HolidayResource::class;
}
