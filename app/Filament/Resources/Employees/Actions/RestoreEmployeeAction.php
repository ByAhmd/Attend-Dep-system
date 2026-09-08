<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;

/**
 * Brings a deleted account back.
 *
 * The way to find one is the "Deleted accounts" filter on the list, which
 * is the only place a deleted row is shown. Restoring returns the account
 * exactly as it was, status included: an account switched off before it was
 * deleted comes back switched off, so nobody regains access by being
 * restored.
 *
 * Filament's own rule - visible only on a deleted row - is kept and the
 * policy added to it, because RestoreAction sets its visibility in setUp()
 * and this closure replaces it wholesale.
 */
final class RestoreEmployeeAction
{
    public static function make(): RestoreAction
    {
        return RestoreAction::make()
            ->label(__('employees.actions.restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->modalHeading(fn (User $record): string => __('employees.actions.restore_heading', [
                'name' => $record->name,
            ]))
            ->modalDescription(__('employees.actions.restore_description'))
            ->modalSubmitActionLabel(__('employees.actions.restore_confirm'))
            ->successNotificationTitle(fn (User $record): string => __('employees.notifications.restored', [
                'name' => $record->name,
            ]))
            ->visible(fn (User $record): bool => $record->trashed()
                && EmployeeResource::canRestore($record));
    }
}
