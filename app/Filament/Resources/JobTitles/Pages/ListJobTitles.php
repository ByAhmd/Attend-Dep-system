<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Pages;

use App\Filament\Resources\JobTitles\JobTitleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListJobTitles extends ListRecords
{
    protected static string $resource = JobTitleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * What a title is, said above the list that hands them out.
     *
     * An administrator reading a screen of labels beside an Employees
     * screen full of roles will reasonably wonder whether one of these
     * grants anything. It does not, and the cheapest place to answer that
     * is here rather than in a document nobody opens.
     */
    public function getSubheading(): string
    {
        return __('job_titles.pages.list.subheading');
    }
}
