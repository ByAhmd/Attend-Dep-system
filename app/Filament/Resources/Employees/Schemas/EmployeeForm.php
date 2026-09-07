<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Create and edit an account.
 *
 * There is no password on this form at all. A new account is created
 * pending and its owner chooses their own password through the invitation
 * link; an existing one is changed through the "Reset password" action,
 * which asks for its own confirmation - an optional password field on the
 * edit form is the classic way to overwrite a password while correcting a
 * typo in a name.
 */
final class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('employees.sections.details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('employees.fields.name'))
                            ->placeholder(__('employees.placeholders.name'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100),

                        TextInput::make('email')
                            ->label(__('employees.fields.email'))
                            ->placeholder(__('employees.placeholders.email'))
                            ->email()
                            ->required()
                            ->maxLength(150)
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => __('employees.validation.email_unique'),
                            ]),
                    ])
                    ->columns(1),

                // Role locks when the administrator opens their own account,
                // and status has no place on the create form: a new account
                // is pending until its owner sets a password. Both disabled
                // inputs are presentation only; the edit page strips the
                // keys again before saving.
                Section::make(__('employees.sections.access'))
                    ->description(fn (?User $record): ?string => $record instanceof User
                        ? null
                        : __('employees.helpers.invitation_on_create'))
                    ->schema([
                        Select::make('role')
                            ->label(__('employees.fields.role'))
                            ->options(UserRole::options())
                            ->required()
                            ->default(UserRole::Employee->value)
                            ->disabled(fn (?User $record): bool => self::isOwnAccount($record))
                            ->helperText(fn (?User $record): string => self::isOwnAccount($record)
                                ? __('employees.helpers.own_access')
                                : __('employees.helpers.role')),

                        Select::make('status')
                            ->label(__('employees.fields.status'))
                            ->options(fn (?User $record): array => self::statusOptions($record))
                            ->required()
                            ->hiddenOn('create')
                            ->disabled(fn (?User $record): bool => self::isOwnAccount($record) || self::isPending($record))
                            ->helperText(fn (?User $record): string => match (true) {
                                self::isOwnAccount($record) => __('employees.helpers.own_access'),
                                self::isPending($record) => __('employees.helpers.pending_status'),
                                default => __('employees.helpers.status'),
                            }),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * The statuses an administrator may choose.
     *
     * Pending is not one of them: an account enters that state by being
     * created and leaves it by its owner setting a password. It is listed
     * only while the record is in it, so the select can display the value
     * it holds instead of showing an empty box.
     *
     * @return array<string, string>
     */
    private static function statusOptions(?User $record): array
    {
        $options = [
            UserStatus::Active->value => UserStatus::Active->label(),
            UserStatus::Inactive->value => UserStatus::Inactive->label(),
        ];

        return self::isPending($record)
            ? [UserStatus::Pending->value => UserStatus::Pending->label()] + $options
            : $options;
    }

    /**
     * True while the record on the form is the signed-in administrator's
     * own account. Null is the create form, which belongs to nobody yet.
     */
    private static function isOwnAccount(?User $record): bool
    {
        return $record instanceof User && ! EmployeeResource::canManageAccess($record);
    }

    private static function isPending(?User $record): bool
    {
        return $record instanceof User && $record->status->isPending();
    }
}
