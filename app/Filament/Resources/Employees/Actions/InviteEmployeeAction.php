<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Actions;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use App\Services\Users\EmployeeInvitationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Issues an invitation from the interface and tells the administrator what
 * became of it.
 *
 * The button is the resend form of it - offered from the list and the edit
 * page while an account is still waiting - but the create page hands the
 * first invitation to the same method, so "invite and report" is written
 * once and an administrator sees the same wording either way.
 *
 * Resending is deliberately a confirmed action: it replaces the outstanding
 * token, so a link already in the employee's inbox stops working.
 */
final class InviteEmployeeAction
{
    public static function make(): Action
    {
        return Action::make('resendInvitation')
            ->label(__('employees.actions.resend_invitation'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('employees.actions.resend_invitation_heading', [
                'name' => $record->name,
            ]))
            ->modalDescription(__('employees.actions.resend_invitation_description'))
            ->visible(fn (User $record): bool => self::isInvitable($record))
            ->action(fn (User $record) => self::invite($record));
    }

    /**
     * Issue the invitation and put the outcome on screen.
     *
     * The link is always usable; only the email may have gone nowhere, and
     * when it has the administrator is told to pass the link on by hand
     * rather than left waiting for a message that will never arrive.
     */
    public static function invite(User $employee): void
    {
        $invitation = app(EmployeeInvitationService::class)->invite($employee);

        if ($invitation->emailed) {
            Notification::make()
                ->title(__('employees.notifications.invited'))
                ->body(__('employees.notifications.invited_body', ['email' => $employee->email]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('employees.notifications.invitation_not_emailed'))
            ->body(__('employees.notifications.invitation_not_emailed_body', ['name' => $employee->name]))
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Both halves of the question: the account is still waiting, and this
     * administrator is allowed to touch its access at all.
     */
    public static function isInvitable(User $record): bool
    {
        return app(EmployeeInvitationService::class)->canBeInvited($record)
            && EmployeeResource::canManageAccess($record);
    }
}
