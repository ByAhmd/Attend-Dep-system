<?php

declare(strict_types=1);

namespace App\Filament\Resources\Holidays\Tables;

use App\Models\Holiday;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The holiday calendar, soonest first.
 *
 * Both names are columns, because the administrator entering "عيد الفطر"
 * beside "Eid al-Fitr" proofreads the pair here, not one half of it. The
 * two dates are monospaced digits like every date in this panel, and the
 * length is derived from the pair already loaded rather than stored.
 */
final class HolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('holidays.fields.name_ar'))
                    ->weight(FontWeight::SemiBold)
                    ->grow()
                    ->searchable(),

                TextColumn::make('name_en')
                    ->label(__('holidays.fields.name_en'))
                    ->searchable(),

                TextColumn::make('starts_on')
                    ->label(__('holidays.fields.starts_on'))
                    ->date('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                TextColumn::make('ends_on')
                    ->label(__('holidays.fields.ends_on'))
                    ->date('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                TextColumn::make('days')
                    ->label(__('holidays.fields.days'))
                    ->state(fn (Holiday $record): string => (string) $record->dayCount())
                    ->extraAttributes(['class' => 'fi-numeric'])
                    ->color('gray'),
            ])
            ->defaultSort('starts_on', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label(__('holidays.actions.edit')),

                DeleteAction::make()
                    ->label(__('holidays.actions.delete')),
            ])
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading(__('holidays.empty.heading'))
            ->emptyStateDescription(__('holidays.empty.description'));
    }
}
