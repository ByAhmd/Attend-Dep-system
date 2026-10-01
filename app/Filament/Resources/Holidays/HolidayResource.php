<?php

declare(strict_types=1);

namespace App\Filament\Resources\Holidays;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Holidays\Pages\CreateHoliday;
use App\Filament\Resources\Holidays\Pages\EditHoliday;
use App\Filament\Resources\Holidays\Pages\ListHolidays;
use App\Filament\Resources\Holidays\Schemas\HolidayForm;
use App\Filament\Resources\Holidays\Tables\HolidaysTable;
use App\Models\Holiday;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The official holidays the absence list and the monthly report respect.
 *
 * Full CRUD like the job titles above it: a list the owner extends
 * themselves, a few times a year. It sits under System beneath Attendance
 * settings because it is the calendar half of the same configuration -
 * the settings say which hours a working day has, this screen says which
 * days are not working days at all.
 *
 * No $recordTitleAttribute, so this resource never joins global search,
 * for the same reason the job titles do not.
 */
final class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $slug = 'holidays';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::CalendarDays;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('holidays.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('holidays.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('holidays.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return HolidayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HolidaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHolidays::route('/'),
            'create' => CreateHoliday::route('/create'),
            'edit' => EditHoliday::route('/{record}/edit'),
        ];
    }
}
