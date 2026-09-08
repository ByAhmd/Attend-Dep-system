<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Pages;

use App\Filament\Resources\JobTitles\JobTitleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateJobTitle extends CreateRecord
{
    protected static string $resource = JobTitleResource::class;
}
