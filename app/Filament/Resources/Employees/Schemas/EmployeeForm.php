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
 * The password is set here only when the account is created. Afterwards it
 * is changed through the "Reset password" action, which asks for its own
 * confirmation - an optional password field on the edit form is the
 * classic way to overwrite a password while correcting a typo in a name.
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

                // Both selects lock when the administrator opens their own
                // account. The disabled input is presentation only; the
                // edit page strips these keys again before saving.
                Section::make(__('employees.sections.access'))
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
                            ->options(UserStatus::options())
                            ->required()
                            ->default(UserStatus::Active->value)
                            ->disabled(fn (?User $record): bool => self::isOwnAccount($record))
                            ->helperText(fn (?User $record): string => self::isOwnAccount($record)
                                ? __('employees.helpers.own_access')
                                : __('employees.helpers.status')),
                    ])
                    ->columns(1),

                // The section carries the same create-only condition as the
                // fields inside it, so the edit form shows no empty card.
                Section::make(__('employees.sections.password'))
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('password')
                            ->label(__('employees.fields.password'))
                            ->helperText(__('employees.helpers.password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->confirmed()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->visibleOn('create'),

                        TextInput::make('password_confirmation')
                            ->label(__('employees.fields.password_confirmation'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false)
                            ->visibleOn('create'),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * True while the record on the form is the signed-in administrator's
     * own account. Null is the create form, which belongs to nobody yet.
     */
    private static function isOwnAccount(?User $record): bool
    {
        return $record instanceof User && ! EmployeeResource::canManageAccess($record);
    }
}
