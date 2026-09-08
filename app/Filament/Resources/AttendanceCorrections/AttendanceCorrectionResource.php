<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceCorrections;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AttendanceCorrections\Pages\ListAttendanceCorrections;
use App\Filament\Resources\AttendanceCorrections\Schemas\AttendanceCorrectionInfolist;
use App\Filament\Resources\AttendanceCorrections\Tables\AttendanceCorrectionsTable;
use App\Models\AttendanceCorrection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Requests to correct a recorded attendance time - read, then decide.
 *
 * One page, the list, with the record inspected in a modal over it. There
 * is no create page: an administrator never files a correction, because a
 * correction is a claim about where somebody was and only that person can
 * make it. There is no edit page either, for the same reason a decided
 * request is never re-decided: un-approving would mean un-writing an
 * attendance amendment, which either erases the archived original or leaves
 * an original nothing explains. The remedy for a wrong decision is a second
 * correction, which is also what actually happened.
 *
 * No $recordTitleAttribute, so this resource stays out of global search:
 * every searchable resource costs a query per keystroke in the top bar, and
 * a queue is read by opening it, not by searching for it.
 */
final class AttendanceCorrectionResource extends Resource
{
    protected static ?string $model = AttendanceCorrection::class;

    protected static ?string $slug = 'attendance-corrections';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::PencilSquare;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Requests;
    }

    public static function getNavigationLabel(): string
    {
        return __('corrections.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('corrections.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('corrections.navigation.plural_model');
    }

    public static function infolist(Schema $schema): Schema
    {
        return AttendanceCorrectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceCorrectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceCorrections::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Whether the signed-in administrator may answer this request.
     *
     * Asked by the row actions and by the record modal's footer alike, so
     * the two can never drift apart: a button offered where the gate says
     * no is a promise the service is about to break.
     *
     * The question goes through the Gate rather than to a policy class by
     * name, which is how every other authorisation in this panel is asked
     * and which keeps the answer in one place - the policy.
     */
    public static function canDecide(?Model $record): bool
    {
        return $record instanceof AttendanceCorrection && Gate::allows('decide', $record);
    }
}
