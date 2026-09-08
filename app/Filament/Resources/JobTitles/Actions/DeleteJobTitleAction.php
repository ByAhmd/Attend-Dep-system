<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\JobTitle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

/**
 * Deletes a job title nobody holds.
 *
 * The foreign key refuses to delete a title somebody wears, and a refusal
 * arriving as a database error is a screen that blames the reader for
 * pressing a button it offered them. So the same rule is said three times,
 * each layer covering the one above it:
 *
 * 1. the modal states how many accounts hold the title and offers no
 *    submit button at all - a disabled button invites a second press, and
 *    a person who presses twice concludes the screen is broken;
 * 2. beside the explanation it offers the list of those accounts, because
 *    the next question after "you cannot" is always "who has it?";
 * 3. the delete itself asks again under the click, since the title may
 *    have been handed to somebody in the seconds the modal was open.
 *
 * The count is read from the row the table already loaded where there is
 * one (the list runs withCount('users')), and asked for directly on the
 * edit page, which has no such count. Either way it is one figure per
 * modal, not one per row.
 */
final class DeleteJobTitleAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('job_titles.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->modalHeading(fn (JobTitle $record): string => __('job_titles.actions.delete_heading', [
                'name' => $record->displayName(),
            ]))
            // A choice string, because "٣ حساب" is not a sentence anybody
            // writes: Arabic needs four shapes of this warning and English
            // two, and trans_choice() is what picks between them.
            ->modalDescription(fn (JobTitle $record): string => self::holders($record) > 0
                ? trans_choice('job_titles.actions.delete_blocked_description', self::holders($record))
                : __('job_titles.actions.delete_description'))
            ->modalSubmitActionLabel(__('job_titles.actions.delete_confirm'))
            ->modalSubmitAction(fn (JobTitle $record): ?bool => self::holders($record) > 0 ? false : null)
            ->extraModalFooterActions(fn (JobTitle $record): array => self::holders($record) > 0
                ? [self::showHolders($record)]
                : [])
            ->successNotificationTitle(fn (JobTitle $record): string => __('job_titles.notifications.deleted', [
                'name' => $record->displayName(),
            ]))
            ->using(function (JobTitle $record): bool {
                if ($record->users()->exists()) {
                    Notification::make()
                        ->title(__('job_titles.notifications.delete_blocked', ['name' => $record->displayName()]))
                        ->body(__('job_titles.notifications.delete_blocked_body'))
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt;
                }

                return (bool) $record->delete();
            });
    }

    /**
     * The employee list, already narrowed to the accounts holding this
     * title. The filter it names is declared on EmployeesTable; a filter
     * that ever stopped existing would be ignored rather than break the
     * link.
     */
    private static function showHolders(JobTitle $record): Action
    {
        return Action::make('showHolders')
            ->label(__('job_titles.actions.show_holders'))
            ->icon(Heroicon::OutlinedUsers)
            ->color('gray')
            ->url(EmployeeResource::getUrl('index', [
                'filters' => ['job_title_id' => ['value' => $record->getKey()]],
            ]));
    }

    /**
     * How many accounts hold the title.
     */
    private static function holders(JobTitle $record): int
    {
        $loaded = $record->getAttributes()['users_count'] ?? null;

        if (is_numeric($loaded)) {
            return (int) $loaded;
        }

        return $record->users()->count();
    }
}
