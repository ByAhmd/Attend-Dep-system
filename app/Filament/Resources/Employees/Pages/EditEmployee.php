<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\CopyInvitationLinkAction;
use App\Filament\Resources\Employees\Actions\DeleteEmployeeAction;
use App\Filament\Resources\Employees\Actions\InviteEmployeeAction;
use App\Filament\Resources\Employees\Actions\ResetPasswordAction;
use App\Filament\Resources\Employees\Actions\RestoreEmployeeAction;
use App\Filament\Resources\Employees\Actions\ToggleStatusAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

/**
 * The header carries the same actions as the list row, so an administrator
 * who opened an account to read it does not have to go back to act on it.
 *
 * Delete and Restore are among them and both belong to the super
 * administrator; each hides itself when its own rule says no.
 */
final class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CopyInvitationLinkAction::make(),
            InviteEmployeeAction::make(),
            ResetPasswordAction::make(),
            ToggleStatusAction::make(),
            RestoreEmployeeAction::make(),
            DeleteEmployeeAction::make(),
        ];
    }

    /**
     * The form disables role and status on accounts this administrator may
     * not touch, but a disabled input is a browser courtesy, not a
     * guarantee. The rules are asked again here, on the data that actually
     * arrived, and they are two separate questions: only the super
     * administrator appoints or removes an administrator, while status and
     * password are any administrator's to manage on anybody but themselves
     * and the super administrator.
     *
     * A pending account's status is dropped for a third reason: it is not
     * an editable field. Saving it as active would produce an account that
     * looks usable, has no password, and can never sign in - the invitation
     * or the deactivate action are the only ways out of that state.
     *
     * The job title is checked last, against the titles this account was
     * entitled to be offered - the active ones and the one it already holds.
     * It grants nothing either way; a retired title is simply one nobody is
     * choosing any more.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! EmployeeResource::canManageRole($record)) {
            unset($data['role']);
        }

        if (! EmployeeResource::canManageAccess($record)) {
            unset($data['status']);
        }

        if ($record instanceof User && $record->status->isPending()) {
            unset($data['status']);
        }

        return EmployeeForm::withKnownJobTitle($data, $record instanceof User ? $record : null);
    }
}
