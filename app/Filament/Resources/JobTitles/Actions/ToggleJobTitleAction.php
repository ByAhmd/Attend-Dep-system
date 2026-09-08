<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Actions;

use App\Models\JobTitle;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Retires a title, or brings it back.
 *
 * This is the answer to "we do not use this one any more", and it is the
 * only answer once anybody holds it: the foreign key refuses the delete,
 * and rightly so - a title is part of how the people who hold it are
 * described, and removing it would rewrite their accounts to say nothing.
 * Retiring stops it being offered and leaves every existing holder exactly
 * as they are, which is what was actually meant.
 *
 * The confirmation says that consequence rather than asking whether the
 * administrator is sure, because "sure" is not the thing they are
 * uncertain about.
 */
final class ToggleJobTitleAction
{
    public static function make(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (JobTitle $record): string => $record->is_active
                ? __('job_titles.actions.toggle_retire')
                : __('job_titles.actions.toggle_activate'))
            ->icon(fn (JobTitle $record): Heroicon => $record->is_active
                ? Heroicon::OutlinedArchiveBox
                : Heroicon::OutlinedCheckCircle)
            ->color(fn (JobTitle $record): string => $record->is_active ? 'gray' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (JobTitle $record): string => $record->is_active
                ? __('job_titles.actions.toggle_retire_heading', ['name' => $record->displayName()])
                : __('job_titles.actions.toggle_activate_heading', ['name' => $record->displayName()]))
            ->modalDescription(fn (JobTitle $record): string => $record->is_active
                ? __('job_titles.actions.toggle_retire_description')
                : __('job_titles.actions.toggle_activate_description'))
            ->action(function (JobTitle $record, HasActions $livewire): void {
                $retiring = $record->is_active;

                $record->forceFill(['is_active' => ! $retiring])->save();

                // On the edit page the form still holds the old flag; left
                // alone, the next Save would quietly put it back.
                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['is_active']);
                }

                Notification::make()
                    ->title($retiring
                        ? __('job_titles.notifications.retired', ['name' => $record->displayName()])
                        : __('job_titles.notifications.activated', ['name' => $record->displayName()]))
                    ->success()
                    ->send();
            });
    }
}
