<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances\Tables;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Support\Geo\Meters;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every check-in and check-out, newest day first.
 *
 * Distances are shown in whole metres because that is the unit the rule is
 * written in; the centimetres stay in the database for the audit.
 */
final class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('attendance.fields.employee'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('attendance_date')
                    ->label(__('attendance.fields.date'))
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('check_in_at')
                    ->label(__('attendance.fields.check_in_at'))
                    ->time('H:i'),

                TextColumn::make('check_out_at')
                    ->label(__('attendance.fields.check_out_at'))
                    ->time('H:i')
                    ->placeholder(__('attendance.placeholders.no_check_out')),

                TextColumn::make('check_in_distance_from_company')
                    ->label(__('attendance.fields.check_in_distance'))
                    ->formatStateUsing(fn (string $state): string => self::meters($state)),

                TextColumn::make('check_out_distance_from_company')
                    ->label(__('attendance.fields.check_out_distance'))
                    ->formatStateUsing(fn (string $state): string => self::meters($state))
                    ->placeholder('—'),

                // Derived on the model, never stored: an open record is a
                // live session today and a missing check-out on any earlier day.
                TextColumn::make('status')
                    ->label(__('attendance.fields.status'))
                    ->badge()
                    ->state(fn (Attendance $record): AttendanceStatus => $record->status())
                    ->formatStateUsing(fn (AttendanceStatus $state): string => $state->label())
                    ->color(fn (AttendanceStatus $state): string => $state->color()),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('attendance_date')
                ->orderByDesc('check_in_at'))
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('attendance.filters.employee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                Filter::make('date')
                    ->label(__('attendance.filters.date'))
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('attendance.filters.date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['date'] ?? null),
                        fn (Builder $nested): Builder => $nested->where('attendance_date', $data['date']),
                    )),

                Filter::make('date_range')
                    ->label(__('attendance.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('attendance.filters.from')),
                        DatePicker::make('until')
                            ->label(__('attendance.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $nested): Builder => $nested->where('attendance_date', '>=', $data['from']),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $nested): Builder => $nested->where('attendance_date', '<=', $data['until']),
                        )),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('attendance.admin_actions.view')),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('attendance.empty.heading'))
            ->emptyStateDescription(__('attendance.empty.description'));
    }

    private static function meters(string $meters): string
    {
        return __('attendance.units.meters', ['value' => Meters::format($meters)]);
    }
}
