<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\CopyInvitationLinkAction;
use App\Filament\Resources\Employees\Actions\InviteEmployeeAction;
use App\Filament\Resources\Employees\Actions\ResetPasswordAction;
use App\Filament\Resources\Employees\Actions\ToggleStatusAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete in the header - accounts are deactivated from here instead,
 * through the same actions the list offers.
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
        ];
    }

    /**
     * The form disables role and status on the administrator's own account,
     * but a disabled input is a browser courtesy, not a guarantee. The
     * policy is asked again here, on the data that actually arrived.
     *
     * A pending account's status is dropped for the same reason: it is not
     * an editable field. Saving it as active would produce an account that
     * looks usable, has no password, and can never sign in - the invitation
     * or the deactivate action are the only ways out of that state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! EmployeeResource::canManageAccess($record)) {
            unset($data['role'], $data['status']);
        }

        if ($record instanceof User && $record->status->isPending()) {
            unset($data['status']);
        }

        return $data;
    }
}
