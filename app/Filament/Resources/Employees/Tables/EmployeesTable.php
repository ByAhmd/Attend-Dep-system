<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Tables;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Employees\Actions\ResetPasswordAction;
use App\Filament\Resources\Employees\Actions\ToggleStatusAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Employee list. No bulk actions and no delete: accounts are switched off
 * one at a time, with a confirmation naming the person.
 */
final class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('employees.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('employees.fields.email'))
                    ->searchable(),

                TextColumn::make('role')
                    ->label(__('employees.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('employees.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => $state->label())
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Inactive => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label(__('employees.fields.created_at'))
                    ->dateTime('Y-m-d')
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
            ])
            ->recordActions([
                EditAction::make(),
                ResetPasswordAction::make(),
                ToggleStatusAction::make(),
            ])
            ->emptyStateHeading(__('employees.empty.heading'))
            ->emptyStateDescription(__('employees.empty.description'));
    }
}
