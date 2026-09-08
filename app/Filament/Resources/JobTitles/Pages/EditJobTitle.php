<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Pages;

use App\Filament\Resources\JobTitles\Actions\DeleteJobTitleAction;
use App\Filament\Resources\JobTitles\Actions\ToggleJobTitleAction;
use App\Filament\Resources\JobTitles\JobTitleResource;
use Filament\Resources\Pages\EditRecord;

final class EditJobTitle extends EditRecord
{
    protected static string $resource = JobTitleResource::class;

    /**
     * The same two acts the row menu offers, because somebody who opened
     * this title to rename it is the person most likely to have decided it
     * should be retired instead - and sending them back to the list to do
     * it would be a screen's worth of walking.
     *
     * Both actions come from Actions/ rather than being written twice: a
     * delete that explained itself in the list and threw a database error
     * here would be the same rule with two different manners.
     */
    protected function getHeaderActions(): array
    {
        return [
            ToggleJobTitleAction::make(),
            DeleteJobTitleAction::make(),
        ];
    }
}
