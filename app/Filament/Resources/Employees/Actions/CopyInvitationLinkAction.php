<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Actions;

use App\Models\User;
use App\Services\Users\EmployeeInvitationService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Shows the invitation link so an administrator can pass it on by hand.
 *
 * This is the path that always works: with no mail configured - which is
 * how this deployment runs today - it is the only way an invited employee
 * ever receives their link. The URL sits in a read-only text input rather
 * than a copy button alone, so it can be selected and copied manually if
 * the browser refuses clipboard access.
 *
 * Opening the modal issues a fresh link, because the token is stored hashed
 * and the one that was emailed cannot be read back. The description says so:
 * whatever link was sent before stops working here.
 */
final class CopyInvitationLinkAction
{
    public static function make(): Action
    {
        return Action::make('copyInvitationLink')
            ->label(__('employees.actions.invitation_link'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->modalHeading(fn (User $record): string => __('employees.actions.invitation_link_heading', [
                'name' => $record->name,
            ]))
            ->modalDescription(__('employees.actions.invitation_link_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('employees.actions.invitation_link_close'))
            ->fillForm(fn (User $record): array => [
                'invitation_link' => app(EmployeeInvitationService::class)->issueLink($record),
            ])
            ->schema([
                TextInput::make('invitation_link')
                    ->label(__('employees.fields.invitation_link'))
                    ->helperText(__('employees.helpers.invitation_link', [
                        'minutes' => (int) config('auth.passwords.users.expire', 60),
                    ]))
                    ->readOnly()
                    ->copyable(copyMessage: __('employees.notifications.link_copied')),
            ])
            ->visible(fn (User $record): bool => InviteEmployeeAction::isInvitable($record));
    }
}
