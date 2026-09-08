<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances;

use App\Enums\NavigationGroup;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Attendances\Schemas\AttendanceInfolist;
use App\Filament\Resources\Attendances\Tables\AttendancesTable;
use App\Models\Attendance;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance records, read-only.
 *
 * One page - the list - and a modal to inspect a record. There is no
 * create, edit or delete page because AttendanceWorkflow is the only
 * writer; a record an administrator could correct afterwards would no
 * longer be evidence of where the employee was.
 *
 * This is also the resource the top bar's search box looks in. An
 * administrator opening that box is looking for what somebody did on a day,
 * and this table is the only place that answers; the employee accounts
 * beside it in the results answer a different question - who this person is
 * - and both are worth reaching from one field.
 */
final class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $slug = 'attendances';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ClipboardDocumentCheck;

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

    /**
     * What the top bar's search box looks in.
     *
     * The employee's name, because a session has no name of its own and
     * "who" is the only word an administrator has when they open that box.
     * The search runs through the relation, so the constraint MySQL sees is
     * a semi-join against a table of a few dozen employees keyed by the
     * indexed attendances.user_id - not a scan of every session ever
     * recorded, which is what searching a column on this table would be.
     *
     * A date is handled separately, in applyGlobalSearchAttributeConstraints
     * below, because it can be compared rather than matched.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['user.name'];
    }

    /**
     * Newest first, with the employee already loaded.
     *
     * The result list is capped at fifty rows, so without an order the fifty
     * an administrator is shown are whichever fifty the database reached
     * first - in practice the oldest sessions in the table, which is the
     * opposite of what somebody searching wants. The ordering is the one the
     * list page itself uses and rides the same (attendance_date, check_out_at)
     * index; the eager load is what keeps naming fifty employees at one
     * query rather than fifty.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with('user')
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in_at')
            ->orderByDesc('id');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record instanceof Attendance ? $record->user->name : '';
    }

    /**
     * The three things that tell one of an employee's sessions from another:
     * which day it was, when it ran, and how it ended. Without them a search
     * for a name answers with fifty identical lines carrying that name.
     *
     * Every value is already in memory - the query above loaded the employee
     * and the two moments are columns - so the detail costs nothing per row.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Attendance) {
            return [];
        }

        $checkOut = $record->check_out_at?->format('H:i') ?? __('attendance.placeholders.no_check_out');

        return [
            (string) __('attendance.fields.date') => $record->attendance_date->format('Y-m-d'),
            (string) __('attendance.fields.duration') => $record->check_in_at->format('H:i').' – '.$checkOut,
            (string) __('attendance.fields.status') => $record->status()->label(),
        ];
    }

    /**
     * The result opens the record, not merely the page it is on.
     *
     * This resource has no view page - the record is inspected in a modal
     * over the list - so the URL is the list carrying the parameters that
     * mount that modal on arrival. A search result that landed on an
     * unfiltered list of every session would be a result in name only.
     */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        if (! self::canView($record)) {
            return null;
        }

        return self::getUrl(parameters: [
            'tableAction' => 'view',
            'tableActionRecord' => $record->getKey(),
        ]);
    }

    /**
     * A date is compared, not matched.
     *
     * Filament's default turns every term into `like '%term%'`, and against
     * a date column that is both wrong - '2026-09-01' would also match
     * nothing, since MySQL compares the DATE not its printed form - and
     * unindexable. A term shaped like a day or a month is therefore answered
     * with a range over attendance_date, the leading column of this table's
     * (attendance_date, check_out_at) index, so searching a date stays an
     * index range scan however many years of sessions accumulate.
     *
     * Anything else is a name, and goes to Filament's own handling.
     *
     * @param  Builder<Attendance>  $query
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $period = self::searchedPeriod($search);

        if ($period === null) {
            parent::applyGlobalSearchAttributeConstraints($query, $search);

            return;
        }

        $query->whereBetween('attendance_date', $period);
    }

    /**
     * The day or the month a search term names, as the two dates bounding
     * it, or null if the term is not a date at all.
     *
     * Only the ISO forms are accepted - 2026-09-08 and 2026-09 - because
     * that is how every date in this interface is printed, in both
     * languages, and a search box that guessed at 09/08 would have to guess
     * which number is the month.
     *
     * @return array{string, string}|null
     */
    private static function searchedPeriod(string $search): ?array
    {
        $term = trim($search);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $term, $day) === 1) {
            [, $year, $month, $date] = array_map('intval', $day);

            return checkdate($month, $date, $year)
                ? [$term, $term]
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $term, $month) === 1) {
            [, $year, $number] = array_map('intval', $month);

            if ($number < 1 || $number > 12) {
                return null;
            }

            $first = CarbonImmutable::create($year, $number, 1);

            return [$first->toDateString(), $first->endOfMonth()->toDateString()];
        }

        return null;
    }
}
