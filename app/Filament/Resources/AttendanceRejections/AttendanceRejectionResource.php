<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceRejections;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AttendanceRejections\Pages\ListAttendanceRejections;
use App\Filament\Resources\AttendanceRejections\Tables\AttendanceRejectionsTable;
use App\Models\AttendanceRejection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The audit trail of check-ins and check-outs refused for where the device
 * was. Read-only: it answers "why could I not check in?" and "who keeps
 * trying from elsewhere?", and an audit that can be edited answers neither.
 */
final class AttendanceRejectionResource extends Resource
{
    protected static ?string $model = AttendanceRejection::class;

    protected static ?string $slug = 'rejected-attempts';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return __('rejections.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('rejections.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rejections.navigation.plural_model');
    }

    public static function table(Table $table): Table
    {
        return AttendanceRejectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceRejections::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
