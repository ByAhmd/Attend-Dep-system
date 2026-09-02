<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Actions;

use App\Enums\UserStatus;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Deactivates an active account, or reactivates an inactive one.
 *
 * Deactivation is the system's only way to remove someone: their history
 * stays, and EnsureAccountIsActive ends any session they still hold. The
 * confirmation therefore names the person and says what happens to them.
 */
final class ToggleStatusAction
{
    public static function make(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (User $record): string => $record->isActive()
                ? __('employees.actions.deactivate')
                : __('employees.actions.activate'))
            ->icon(fn (User $record): Heroicon => $record->isActive()
                ? Heroicon::OutlinedNoSymbol
                : Heroicon::OutlinedCheckCircle)
            ->color(fn (User $record): string => $record->isActive() ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => $record->isActive()
                ? __('employees.actions.deactivate_heading', ['name' => $record->name])
                : __('employees.actions.activate_heading', ['name' => $record->name]))
            ->modalDescription(fn (User $record): ?string => $record->isActive()
                ? __('employees.actions.deactivate_description')
                : null)
            ->visible(fn (User $record): bool => EmployeeResource::canManageAccess($record))
            ->action(function (User $record, HasActions $livewire): void {
                $activating = ! $record->isActive();

                $record->forceFill([
                    'status' => $activating ? UserStatus::Active : UserStatus::Inactive,
                ])->save();

                // On the edit page the form still holds the old status;
                // left alone, the next Save would quietly put it back.
                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['status']);
                }

                Notification::make()
                    ->title($activating
                        ? __('employees.notifications.activated', ['name' => $record->name])
                        : __('employees.notifications.deactivated', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }
}
