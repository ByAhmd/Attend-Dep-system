<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\User;
use App\Policies\UserPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee accounts - the one place accounts are created, edited,
 * deactivated and given a new password.
 *
 * There is no delete anywhere on this resource: an account with attendance
 * history is deactivated so the history survives, and UserPolicy denies the
 * delete to everyone in case something asks anyway.
 */
final class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'employees';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('employees.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('employees.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('employees.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    /**
     * Whether the signed-in administrator may change who this account is
     * allowed to be - its role, its status, its password.
     *
     * Asked by the form, the actions and the edit page alike, so the answer
     * comes from the policy in exactly one place: an administrator never
     * changes their own access, and a screen that forgot to ask would let
     * a deployment demote or lock out its last administrator.
     */
    public static function canManageAccess(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $record instanceof User
            && app(UserPolicy::class)->manageAccess($user, $record);
    }
}
