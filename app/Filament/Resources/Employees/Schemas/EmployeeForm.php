<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\JobTitle;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
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
 *
 * Employment type and job title sit in the details section and never in the
 * access one. That separation is the whole argument: UserRole decides what
 * an account may do, while these two describe the person, and a description
 * filed beside the permissions would be read as one within a week.
 *
 * Role and status lock themselves whenever the signed-in administrator may
 * not set them, and say so rather than greying out in silence: your own
 * account, one still waiting for its invitation, a deleted one. Each says
 * why in the same words it uses on every other row, and no helper singles
 * out a particular account or explains a lock in terms of who holds which
 * designation. All of them are presentation; the create and edit pages
 * strip the keys again before writing.
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

                        Grid::make(['default' => 1, 'sm' => 2])
                            ->schema([
                                Select::make('employment_type')
                                    ->label(__('employees.fields.employment_type'))
                                    ->options(EmploymentType::options())
                                    ->required()
                                    ->default(EmploymentType::Employee->value)
                                    ->selectablePlaceholder(false)
                                    ->helperText(__('employees.helpers.employment_type')),

                                // Not required: an account can be created
                                // before anybody has decided what to call the
                                // job, and no title is honest where an
                                // invented one is not. Retired titles are out
                                // of the list except the one this account
                                // already holds.
                                Select::make('job_title_id')
                                    ->label(__('employees.fields.job_title'))
                                    ->options(fn (?User $record): array => JobTitle::selectableOptions($record?->job_title_id))
                                    ->searchable()
                                    ->preload()
                                    ->placeholder(__('employees.placeholders.job_title'))
                                    ->helperText(__('employees.helpers.job_title')),
                            ]),
                    ])
                    ->columns(1),

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
                            ->disabled(fn (?User $record): bool => ! EmployeeResource::canManageRole($record))
                            ->helperText(fn (?User $record): string => self::roleHelper($record)),

                        Select::make('status')
                            ->label(__('employees.fields.status'))
                            ->options(fn (?User $record): array => self::statusOptions($record))
                            ->required()
                            ->hiddenOn('create')
                            ->disabled(fn (?User $record): bool => ! EmployeeResource::canManageAccess($record)
                                || self::isPending($record))
                            ->helperText(fn (?User $record): string => self::statusHelper($record)),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * Why the role select is locked, or what it does when it is not.
     *
     * Every arm here is a sentence any reader could be shown about any row.
     * Nothing in it distinguishes one account from another.
     */
    private static function roleHelper(?User $record): string
    {
        return match (true) {
            self::isOwnAccount($record) => __('employees.helpers.own_access'),
            $record instanceof User && $record->trashed() => __('employees.helpers.deleted_account'),
            ! EmployeeResource::canManageRole($record) => __('employees.helpers.role_locked'),
            default => __('employees.helpers.role'),
        };
    }

    /**
     * The same rule for the status select, in the same shape: the generic
     * sentence under a locked select is the one every other locked row
     * carries, and a lock this screen declines to explain is a lock nobody
     * can read anything into.
     */
    private static function statusHelper(?User $record): string
    {
        return match (true) {
            self::isOwnAccount($record) => __('employees.helpers.own_access'),
            $record instanceof User && $record->trashed() => __('employees.helpers.deleted_account'),
            self::isPending($record) => __('employees.helpers.pending_status'),
            default => __('employees.helpers.status'),
        };
    }

    /**
     * The last word on a submitted job title.
     *
     * The select offers active titles plus the one this account already
     * holds, and Filament refuses anything else; this runs on the data that
     * actually arrived, so a retired title cannot be pinned onto somebody by
     * a hand-built request. Unknown becomes no title rather than an error:
     * the field is optional, so "none" is a value the form already accepts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withKnownJobTitle(array $data, ?User $record = null): array
    {
        if (! array_key_exists('job_title_id', $data)) {
            return $data;
        }

        $submitted = $data['job_title_id'];

        $data['job_title_id'] = is_numeric($submitted)
            && array_key_exists((int) $submitted, JobTitle::selectableOptions($record?->job_title_id))
                ? (int) $submitted
                : null;

        return $data;
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
        $user = auth()->user();

        return $record instanceof User && $user instanceof User && $record->is($user);
    }

    private static function isPending(?User $record): bool
    {
        return $record instanceof User && $record->status->isPending();
    }
}
