<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Attendances\Schemas\AttendanceInfolist;
use App\Filament\Resources\Attendances\Tables\AttendancesTable;
use App\Models\Attendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Attendance records, read-only.
 *
 * One page - the list - and a modal to inspect a record. There is no
 * create, edit or delete page because AttendanceWorkflow is the only
 * writer; a record an administrator could correct afterwards would no
 * longer be evidence of where the employee was.
 */
final class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $slug = 'attendances';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return __('attendance.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('attendance.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('attendance.navigation.plural_model');
    }

    public static function infolist(Schema $schema): Schema
    {
        return AttendanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendances::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
