<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceRejections\Tables;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Models\AttendanceRejection;
use App\Models\User;
use App\Support\Geo\Coordinates;
use App\Support\Geo\GoogleMapsLink;
use App\Support\Geo\Meters;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rejected attempts, newest first.
 *
 * The reason colour separates the two stories the table tells: a poor fix
 * (warning) is a phone problem to help with, a position outside the radius
 * (danger) is someone not at work.
 */
final class AttendanceRejectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('rejections.fields.employee'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('action')
                    ->label(__('rejections.fields.action'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (AttendanceAction $state): string => $state->label()),

                TextColumn::make('reason')
                    ->label(__('rejections.fields.reason'))
                    ->badge()
                    ->formatStateUsing(fn (AttendanceRejectionReason $state): string => $state->label())
                    ->color(fn (AttendanceRejectionReason $state): string => match ($state) {
                        AttendanceRejectionReason::InsufficientAccuracy => 'warning',
                        AttendanceRejectionReason::OutsideAllowedArea => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('distance_from_company')
                    ->label(__('rejections.fields.distance'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.units.meters', [
                        'value' => Meters::format($state),
                    ]))
                    ->placeholder('—'),

                TextColumn::make('accuracy')
                    ->label(__('rejections.fields.accuracy'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.units.accuracy', [
                        'value' => Meters::format($state),
                    ])),

                TextColumn::make('created_at')
                    ->label(__('rejections.fields.recorded_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('rejections.filters.employee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reason')
                    ->label(__('rejections.filters.reason'))
                    ->options(AttendanceRejectionReason::options()),

                SelectFilter::make('action')
                    ->label(__('rejections.filters.action'))
                    ->options(AttendanceAction::options()),

                Filter::make('date_range')
                    ->label(__('rejections.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('rejections.filters.from')),
                        DatePicker::make('until')
                            ->label(__('rejections.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $nested): Builder => $nested->whereDate('created_at', '>=', $data['from']),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $nested): Builder => $nested->whereDate('created_at', '<=', $data['until']),
                        )),
            ])
            ->recordActions([
                Action::make('openMap')
                    ->label(__('rejections.actions.open_map'))
                    ->icon(Heroicon::OutlinedMap)
                    ->color('gray')
                    ->url(fn (AttendanceRejection $record): string => GoogleMapsLink::to(
                        new Coordinates((float) $record->latitude, (float) $record->longitude),
                    ))
                    ->openUrlInNewTab(),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('rejections.empty.heading'))
            ->emptyStateDescription(__('rejections.empty.description'));
    }
}
