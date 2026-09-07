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
 * Switches an account off, or back on.
 *
 * Deactivation is the system's only way to remove someone: their history
 * stays, and EnsureAccountIsActive ends any session they still hold. The
 * confirmation therefore names the person and says what happens to them.
 * An account still waiting for its invitation can be switched off too -
 * that is how a mistaken invitation is withdrawn.
 *
 * Switching one back on returns it to where it came from. An account that
 * never set a password goes back to pending rather than active, because
 * "active with no password" is a state nobody can sign in from and the
 * invitation actions would be hidden from it.
 */
final class ToggleStatusAction
{
    public static function make(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (User $record): string => self::deactivates($record)
                ? __('employees.actions.deactivate')
                : __('employees.actions.activate'))
            ->icon(fn (User $record): Heroicon => self::deactivates($record)
                ? Heroicon::OutlinedNoSymbol
                : Heroicon::OutlinedCheckCircle)
            ->color(fn (User $record): string => self::deactivates($record) ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => self::deactivates($record)
                ? __('employees.actions.deactivate_heading', ['name' => $record->name])
                : __('employees.actions.activate_heading', ['name' => $record->name]))
            ->modalDescription(fn (User $record): ?string => self::deactivates($record)
                ? __('employees.actions.deactivate_description')
                : null)
            ->visible(fn (User $record): bool => EmployeeResource::canManageAccess($record))
            ->action(function (User $record, HasActions $livewire): void {
                $target = self::targetStatus($record);

                $record->forceFill(['status' => $target])->save();

                // On the edit page the form still holds the old status;
                // left alone, the next Save would quietly put it back.
                if ($livewire instanceof EditRecord) {
                    $livewire->refreshFormData(['status']);
                }

                Notification::make()
                    ->title(match ($target) {
                        UserStatus::Active => __('employees.notifications.activated', ['name' => $record->name]),
                        UserStatus::Inactive => __('employees.notifications.deactivated', ['name' => $record->name]),
                        UserStatus::Pending => __('employees.notifications.reopened', ['name' => $record->name]),
                    })
                    ->success()
                    ->send();
            });
    }

    /**
     * Where the account lands when the button is pressed.
     *
     * An account with no password has never been used, so reactivating it
     * hands it back to the invitation flow instead of pretending it works.
     */
    private static function targetStatus(User $record): UserStatus
    {
        if (self::deactivates($record)) {
            return UserStatus::Inactive;
        }

        return blank($record->password) ? UserStatus::Pending : UserStatus::Active;
    }

    private static function deactivates(User $record): bool
    {
        return $record->status !== UserStatus::Inactive;
    }
}
