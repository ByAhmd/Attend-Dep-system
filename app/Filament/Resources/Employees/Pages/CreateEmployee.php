<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Employees\Actions\InviteEmployeeAction;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Creating an employee no longer means choosing a password for them.
 *
 * The account is stored pending and without one, and the invitation is
 * issued immediately: the employee sets their own password, and until they
 * do the account cannot sign in or record attendance.
 */
final class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Status and password are not on the form, so neither can arrive from
     * the browser - they are stated here because this is what "create an
     * employee" means, not because something might have tampered with them.
     *
     * The role is a different matter. Appointing an administrator is the
     * super administrator's alone, and creating one is appointing one: an
     * ordinary administrator who could mint an admin account here would
     * have every power the rule exists to withhold, one Create button away.
     * The select is disabled for them and the value is dropped again here,
     * on the data that actually arrived.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = UserStatus::Pending;
        $data['password'] = null;

        if (! EmployeeResource::canManageRole(null)) {
            $data['role'] = UserRole::Employee;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof User) {
            InviteEmployeeAction::invite($record);
        }
    }

    /**
     * The invitation notification is the news: whether the email left, and
     * what to do when it did not. A second "Created" toast on top of it
     * would only bury the part that needs reading.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
