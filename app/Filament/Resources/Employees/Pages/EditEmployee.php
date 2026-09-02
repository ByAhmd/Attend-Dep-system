<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\ResetPasswordAction;
use App\Filament\Resources\Employees\Actions\ToggleStatusAction;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete in the header - accounts are deactivated from here instead,
 * through the same action the list offers.
 */
final class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ResetPasswordAction::make(),
            ToggleStatusAction::make(),
        ];
    }

    /**
     * The form disables role and status on the administrator's own account,
     * but a disabled input is a browser courtesy, not a guarantee. The
     * policy is asked again here, on the data that actually arrived.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! EmployeeResource::canManageAccess($this->getRecord())) {
            unset($data['role'], $data['status']);
        }

        return $data;
    }
}
