<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Gives an account a new password, from the list and from the edit page.
 *
 * A separate action rather than a field on the edit form: it asks for the
 * password twice in a modal of its own, so a password is never overwritten
 * as a side effect of saving something else. The model's hashed cast does
 * the hashing; nothing here sees the hash.
 */
final class ResetPasswordAction
{
    public static function make(): Action
    {
        return Action::make('resetPassword')
            ->label(__('employees.actions.reset_password'))
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->modalHeading(fn (User $record): string => __('employees.actions.reset_password_heading', [
                'name' => $record->name,
            ]))
            ->modalDescription(__('employees.actions.reset_password_description'))
            ->schema([
                TextInput::make('password')
                    ->label(__('employees.fields.new_password'))
                    ->helperText(__('employees.helpers.password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->confirmed(),

                TextInput::make('password_confirmation')
                    ->label(__('employees.fields.password_confirmation'))
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->visible(fn (User $record): bool => EmployeeResource::canManageAccess($record))
            ->action(function (array $data, User $record): void {
                $record->forceFill(['password' => $data['password']])->save();

                Notification::make()
                    ->title(__('employees.notifications.password_reset'))
                    ->body(__('employees.notifications.password_reset_body', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }
}
