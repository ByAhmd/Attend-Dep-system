<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Tables;

use App\Enums\EmploymentType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Employees\Actions\CopyInvitationLinkAction;
use App\Filament\Resources\Employees\Actions\DeleteEmployeeAction;
use App\Filament\Resources\Employees\Actions\InviteEmployeeAction;
use App\Filament\Resources\Employees\Actions\ResetPasswordAction;
use App\Filament\Resources\Employees\Actions\RestoreEmployeeAction;
use App\Filament\Resources\Employees\Actions\ToggleStatusAction;
use App\Models\JobTitle;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Employee list. No bulk actions: accounts are switched off, or deleted, one
 * at a time, with a confirmation naming the person.
 *
 * An account still waiting for its invitation is amber rather than green or
 * grey: it is neither working nor switched off, and it is the one row on
 * this screen that needs somebody to do something about it. The three
 * states also carry three different glyphs - a tick, a clock, a closed
 * padlock - so the row still says which is which in a screenshot printed
 * in black and white, or to a reader who cannot separate the colours.
 *
 * A deleted account takes over that same badge with a fourth glyph, because
 * the status column answers one question - can this account get in? - and
 * for a deleted one the answer is "no, it was deleted", not whatever the
 * status column happened to hold on the way out. Deleted rows appear only
 * when the reader asks for them through the trashed filter.
 *
 * The role column says what the role column holds, for every account
 * without exception. No row is marked as anything more than its role: the
 * account designated in the environment is deliberately not distinguished
 * here, and a badge, an icon, a colour or a tooltip that singled it out
 * would be the announcement this screen is meant not to make. The powers
 * are unchanged and live in UserPolicy; only the labelling is gone.
 *
 * A person is one thing, so a person is one column: the position above the
 * name and the address underneath it, the way an address book has always
 * shown it. The search still matches name or address, and the two columns
 * saved go to the states beside them, which are what this screen is read
 * for. The position is composed from two loaded fields, so the title is
 * eager loaded and the list costs the same query whatever it holds.
 */
final class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The position under a name reads two fields of another table,
            // so the title comes with the page rather than one query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('jobTitle'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('employees.fields.name'))
                    ->description(fn (User $record): ?string => $record->positionLabel(), position: 'above')
                    ->description(fn (User $record): string => $record->email)
                    ->weight(FontWeight::SemiBold)
                    ->grow()
                    ->searchable(['name', 'email'])
                    ->sortable(),

                TextColumn::make('role')
                    ->label(__('employees.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label())
                    ->icon(fn (UserRole $state): BackedEnum => $state->isAdmin()
                        ? Heroicon::OutlinedKey
                        : Heroicon::OutlinedUser)
                    // The brand colour marks the accounts that can change
                    // the system; it is not a verdict on anybody, which is
                    // why it is the primary ramp and not a semantic one.
                    ->color(fn (UserRole $state): string => $state->isAdmin() ? 'primary' : 'gray'),

                TextColumn::make('status')
                    ->label(__('employees.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state, User $record): string => $record->trashed()
                        ? __('employees.badges.deleted')
                        : $state->label())
                    ->icon(fn (UserStatus $state, User $record): BackedEnum => match (true) {
                        $record->trashed() => Heroicon::OutlinedTrash,
                        $state === UserStatus::Active => Heroicon::OutlinedCheckCircle,
                        $state === UserStatus::Inactive => Heroicon::OutlinedLockClosed,
                        default => Heroicon::OutlinedClock,
                    })
                    ->color(fn (UserStatus $state, User $record): string => match (true) {
                        $record->trashed() => 'danger',
                        $state === UserStatus::Active => 'success',
                        $state === UserStatus::Inactive => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('created_at')
                    ->label(__('employees.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->label(__('employees.filters.role'))
                    ->options(UserRole::options()),

                SelectFilter::make('status')
                    ->label(__('employees.filters.status'))
                    ->options(UserStatus::options()),

                SelectFilter::make('employment_type')
                    ->label(__('employees.filters.employment_type'))
                    ->options(EmploymentType::options()),

                // Ordered by the Arabic name in both languages, the way the
                // job titles screen orders itself, and labelled in the
                // reader's own language so this list does not become the one
                // place in the panel that answers in Arabic only.
                SelectFilter::make('job_title_id')
                    ->label(__('employees.filters.job_title'))
                    ->relationship('jobTitle', 'name_ar')
                    ->getOptionLabelFromRecordUsing(fn (JobTitle $record): string => $record->displayName())
                    ->searchable()
                    ->preload(),

                // Deleted accounts are out of the list until this is set,
                // which is what "the account disappears" means in practice.
                // Filament's own wording says "records", which on a screen
                // full of attendance would read as the wrong thing.
                TrashedFilter::make()
                    ->label(__('employees.filters.trashed'))
                    ->placeholder(__('employees.filters.trashed_without'))
                    ->trueLabel(__('employees.filters.trashed_with'))
                    ->falseLabel(__('employees.filters.trashed_only')),
            ])
            ->recordActions([
                // One menu rather than up to five labelled buttons. The set
                // changes shape with the account - a pending one offers its
                // invitation, a deleted one only Restore - and a row whose
                // buttons move about is hard to read. It is also the only
                // way this row fits a tablet: five Arabic labels ran to
                // 960px, so on a 1024px screen everything past Edit sat off
                // the edge of a table nobody thinks to scroll sideways.
                ActionGroup::make([
                    EditAction::make(),
                    CopyInvitationLinkAction::make(),
                    InviteEmployeeAction::make(),
                    ResetPasswordAction::make(),
                    ToggleStatusAction::make(),
                    RestoreEmployeeAction::make(),
                    DeleteEmployeeAction::make(),
                ]),
            ])
            // Seven row actions and three badges do not fit a phone;
            // below the sm breakpoint each account becomes a labelled card,
            // so the name, the two states and the buttons that act on them
            // are read together instead of a screen-width apart.
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(__('employees.empty.heading'))
            ->emptyStateDescription(__('employees.empty.description'));
    }
}
