<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;

/**
 * Deletes an account - which here means hide it and keep its records.
 *
 * The confirmation says all three consequences in the owner's own terms,
 * because "Are you sure?" is not enough for a button that removes a
 * colleague: the account can no longer sign in, it disappears from this
 * list, and its attendance history is kept with the person's name on it.
 * The last line is the reason this is a delete an administrator can press
 * without dread, so it is on the screen and not only in a docblock.
 *
 * Offered to the super administrator alone, and never on the super
 * administrator's own row. The visibility rule reads the policy, so the
 * button and the gate can never drift apart.
 */
final class DeleteEmployeeAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('employees.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->modalHeading(fn (User $record): string => __('employees.actions.delete_heading', [
                'name' => $record->name,
            ]))
            ->modalDescription(__('employees.actions.delete_description'))
            ->modalSubmitActionLabel(__('employees.actions.delete_confirm'))
            ->successNotificationTitle(fn (User $record): string => __('employees.notifications.deleted', [
                'name' => $record->name,
            ]))
            ->visible(fn (User $record): bool => EmployeeResource::canDelete($record));
    }
}
