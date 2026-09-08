<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests;

use App\Enums\NavigationGroup;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestInfolist;
use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Requests for days off - read, then decide.
 *
 * The same shape as correction requests, verb for verb: one page, a modal
 * to read the record, approve and reject, and nothing that edits or
 * re-decides. An approval records what was agreed and changes no attendance
 * row; it does not stop the employee checking in, and no screen here may
 * suggest that it does.
 *
 * No $recordTitleAttribute, so this resource stays out of global search.
 */
final class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $slug = 'leave-requests';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::CalendarDays;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Requests;
    }

    public static function getNavigationLabel(): string
    {
        return __('leave.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('leave.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('leave.navigation.plural_model');
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaveRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Whether the signed-in administrator may answer this request. Read by
     * the row actions and by the record modal's footer, so a button is
     * never offered where the gate says no.
     */
    public static function canDecide(?Model $record): bool
    {
        return $record instanceof LeaveRequest && Gate::allows('decide', $record);
    }
}
