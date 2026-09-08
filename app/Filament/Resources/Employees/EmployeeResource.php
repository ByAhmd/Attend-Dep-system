<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\Scopes\AccountSoftDeletingScope;
use App\Models\User;
use App\Policies\UserPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee accounts - the one place accounts are created, edited,
 * deactivated, given a new password, and deleted.
 *
 * Deleting means hide and keep the records: the account leaves this list and
 * can never sign in again, its attendance history stays and still carries
 * the person's name, and it can be restored. Only the super administrator
 * is offered it, and only the super administrator may change a role.
 */
final class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'employees';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Users;

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
     * Deleted accounts are part of this resource, so the trashed filter and
     * the restore action have something to work with. The filter's own
     * default state hides them again, which is what makes a deleted account
     * "disappear from the list" while remaining reachable.
     *
     * The scope named is this model's own, which stands in for Laravel's:
     * see App\Models\Scopes\AccountSoftDeletingScope.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([AccountSoftDeletingScope::class]);
    }

    /**
     * Whether the signed-in administrator may change whether this account
     * can get in - its status, its password.
     *
     * Asked by the form, the actions and the edit page alike, so the answer
     * comes from the policy in exactly one place: an administrator never
     * changes their own access, nobody touches the super administrator's,
     * and a screen that forgot to ask would let a deployment lock out its
     * last administrator.
     *
     * A deleted account is excluded here rather than in each action: it has
     * no access to manage until it is restored, so inviting it, resetting
     * its password or switching it on would all be meaningless.
     *
     * Null is the create form, where there is no account to manage yet.
     */
    public static function canManageAccess(?Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $record instanceof User
            && ! $record->trashed()
            && app(UserPolicy::class)->manageAccess($user, $record);
    }

    /**
     * Whether the signed-in administrator may choose this account's role.
     *
     * Null is the create form: appointing an administrator is the same act
     * whether the account already exists or is being made, so an ordinary
     * administrator cannot do it there either - otherwise the rule would be
     * one Create button wide.
     */
    public static function canManageRole(?Model $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($record === null) {
            return $user->isSuperAdmin();
        }

        return $record instanceof User
            && ! $record->trashed()
            && app(UserPolicy::class)->manageRole($user, $record);
    }
}
