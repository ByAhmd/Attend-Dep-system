<?php

declare(strict_types=1);

namespace App\Filament\Resources\PresencePings\Tables;

use App\Models\PresencePing;
use App\Models\User;
use App\Support\Geo\GoogleMapsLink;
use App\Support\Geo\Meters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Presence pings, newest first.
 *
 * One row is one moment: who, when, how far from the company, how sure the
 * device was, and which side of the radius that puts them on. A ping taken
 * outside the area is the only thing here worth an administrator's
 * attention, so it is the only thing coloured - danger on both the verdict
 * and the distance, so it is visible while scrolling.
 *
 * Accuracy sits next to the distance because it qualifies it: 40 m from the
 * company with a fix good to 15 m is a person at work, and the same 40 m
 * with a fix good to 300 m is barely a statement at all. Both carry
 * tabular figures so the pair can be weighed against each other down a
 * column of a hundred pings - tabular rather than monospaced, because the
 * metre sign in them is Arabic and no monospaced stack has any. The
 * verdict badge carries a pin or a prohibition sign, so the one row worth
 * stopping at is still the one row worth stopping at without its colour.
 */
final class PresencePingsTable
{
    /**
     * Filament's own numeric class: tabular figures, nothing else.
     *
     * @var array<string, string>
     */
    private const TABULAR = ['class' => 'fi-numeric'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('presence.fields.employee'))
                    ->weight(FontWeight::SemiBold)
                    ->grow()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('presence.fields.recorded_at'))
                    ->dateTime('Y-m-d H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                TextColumn::make('distance_from_company')
                    ->label(__('presence.fields.distance'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.units.meters', [
                        'value' => Meters::format($state),
                    ]))
                    ->extraAttributes(self::TABULAR)
                    ->weight(FontWeight::Medium)
                    ->color(fn (PresencePing $record): string => $record->is_inside ? 'gray' : 'danger'),

                TextColumn::make('accuracy')
                    ->label(__('presence.fields.accuracy'))
                    ->formatStateUsing(fn (string $state): string => __('attendance.units.accuracy', [
                        'value' => Meters::format($state),
                    ]))
                    ->extraAttributes(self::TABULAR)
                    ->color('gray'),

                TextColumn::make('is_inside')
                    ->label(__('presence.fields.position'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => __($state ? 'presence.values.inside' : 'presence.values.outside'))
                    ->icon(fn (bool $state): BackedEnum => $state
                        ? Heroicon::OutlinedMapPin
                        : Heroicon::OutlinedNoSymbol)
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('presence.filters.employee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_inside')
                    ->label(__('presence.filters.position'))
                    ->trueLabel(__('presence.filters.inside'))
                    ->falseLabel(__('presence.filters.outside')),

                Filter::make('date_range')
                    ->label(__('presence.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('presence.filters.from')),
                        DatePicker::make('until')
                            ->label(__('presence.filters.until')),
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
                    ->label(__('presence.actions.open_map'))
                    ->icon(Heroicon::OutlinedMap)
                    ->color('gray')
                    ->url(fn (PresencePing $record): string => GoogleMapsLink::to($record->coordinates()))
                    ->openUrlInNewTab(),
            ])
            ->paginated([25, 50, 100])
            // Five columns of quantities and a map button do not fit a
            // phone; below the sm breakpoint each ping becomes a labelled
            // card, so the distance and the accuracy that qualifies it are
            // read together rather than a screen-width apart.
            ->stackedOnMobile()
            // Nothing has reported in: a silent radio, not a failure.
            ->emptyStateIcon(Heroicon::OutlinedSignalSlash)
            ->emptyStateHeading(__('presence.empty.heading'))
            ->emptyStateDescription(__('presence.empty.description'));
    }
}
