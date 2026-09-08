<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceSettings;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AttendanceSettings\Pages\EditAttendanceSetting;
use App\Filament\Resources\AttendanceSettings\Schemas\AttendanceSettingForm;
use App\Models\AttendanceSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The company location and radius - one row, so one screen.
 *
 * The sidebar entry opens the edit page directly; there is no list to pick
 * from and no create, because AttendanceSetting::current() makes the row
 * on first use and the policy refuses a second.
 */
final class AttendanceSettingResource extends Resource
{
    protected static ?string $model = AttendanceSetting::class;

    protected static ?string $slug = 'attendance-settings';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::MapPin;

    // Third in the System group, behind Employees and Job titles. The sort
    // moved from 2 when the job-title list took that place: two entries
    // sharing a sort leaves their order to registration order in the panel
    // provider, which is a detail no reader of this file can see.
    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::System;
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('settings.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('settings.navigation.plural_model');
    }

    public static function form(Schema $schema): Schema
    {
        return AttendanceSettingForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => EditAttendanceSetting::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
