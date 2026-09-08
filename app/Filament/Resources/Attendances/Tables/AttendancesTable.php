<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances\Tables;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Support\Attendance\SessionDuration;
use App\Support\Geo\Meters;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Every session, newest first.
 *
 * One row is one check-in and the check-out that closed it, so an employee
 * who left at noon and came back appears twice on that day. Grouping by
 * date turns the list into days at a glance, and the duration column
 * totals the time inside for whatever the filters have selected.
 *
 * Distances are shown in whole metres because that is the unit the rule is
 * written in; the centimetres stay in the database for the audit.
 *
 * This table is read by scanning, so it is typeset rather than merely
 * listed. The date and the two clock times hold nothing but digits and are
 * set in the monospaced face, which lines them up down the column and says
 * "reading" rather than "sentence". The quantities that carry an Arabic
 * unit - the distances, the length of the session - stay in the interface
 * face: a monospaced stack has no Arabic in it, and the metre sign would be
 * drawn by whatever the browser found instead. They are given Filament's
 * own numeric class, so their figures line up without their letters
 * changing typeface.
 *
 * The columns are ordered as the sentence a reader is actually making:
 * who, on what day, in, out, for how long, and how it ended. The two
 * questions somebody scanning a day asks are the last two, so the duration
 * is the only semibold figure and the state is a badge whose icon says the
 * same thing as its colour: a pin for still inside, a tick for a finished
 * session, a warning triangle for one nobody ever closed. The two
 * distances are the audit rather than the sentence and sit behind the
 * column menu, where an administrator who wants them has them and the
 * table that has to be scanned is not paying for them.
 */
final class AttendancesTable
{
    /**
     * Filament's own numeric class, the one its money and numeric columns
     * already wear: tabular figures, nothing else. Reused rather than
     * reinvented, so the rule lives in one stylesheet.
     *
     * @var array<string, string>
     */
    private const TABULAR = ['class' => 'fi-numeric'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                // The one column allowed to take the leftover width: a name
                // may be long, and everything beside it is a fixed quantity.
                TextColumn::make('user.name')
                    ->label(__('attendance.fields.employee'))
                    ->weight(FontWeight::SemiBold)
                    ->grow()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('attendance_date')
                    ->label(__('attendance.fields.date'))
                    ->date('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                TextColumn::make('check_in_at')
                    ->label(__('attendance.fields.check_in_at'))
                    ->time('H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::Medium),

                TextColumn::make('check_out_at')
                    ->label(__('attendance.fields.check_out_at'))
                    ->time('H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::Medium)
                    ->placeholder(__('attendance.placeholders.no_check_out')),

                // How long the session lasted, computed from the two stored
                // moments rather than kept in a column that could disagree
                // with them. The summary adds up the same subtraction in
                // SQL, so a day (or a filtered range, or one group) reports
                // the time actually spent inside.
                TextColumn::make('duration')
                    ->label(__('attendance.fields.duration'))
                    ->state(fn (Attendance $record): string => SessionDuration::format($record->durationInSeconds()))
                    ->extraAttributes(self::TABULAR)
                    ->weight(FontWeight::SemiBold)
                    ->summarize(
                        Summarizer::make('total_inside')
                            ->label(__('attendance.summaries.total_inside'))
                            ->using(fn (QueryBuilder $query): string => SessionDuration::format(
                                (int) $query->sum(DB::raw('TIMESTAMPDIFF(SECOND, check_in_at, check_out_at)')),
                            )),
                    ),

                // Derived on the model, never stored: an open record is a
                // live session today and a missing check-out on any earlier day.
                TextColumn::make('status')
                    ->label(__('attendance.fields.status'))
                    ->badge()
                    ->state(fn (Attendance $record): AttendanceStatus => $record->status())
                    ->formatStateUsing(fn (AttendanceStatus $state): string => $state->label())
                    ->icon(fn (AttendanceStatus $state): BackedEnum => self::statusIcon($state))
                    ->color(fn (AttendanceStatus $state): string => $state->color()),

                // The audit, behind the column menu.
                //
                // Where the phone was standing is a fact worth keeping and
                // never a fact worth scanning: every row in this table was
                // already inside the radius, or the workflow would have
                // refused it and written it to the rejections instead. Kept
                // visible, the pair pushed the table to 970px inside an
                // 881px frame on an ordinary 1280px desktop, which put the
                // row's own View action off the left edge - the one control
                // on the row, unreachable without scrolling sideways. They
                // are one click away in the column menu, and the View modal
                // shows both in full beside the accuracies that qualify them.
                TextColumn::make('check_in_distance_from_company')
                    ->label(__('attendance.fields.check_in_distance'))
                    ->formatStateUsing(fn (string $state): string => self::meters($state))
                    ->extraAttributes(self::TABULAR)
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('check_out_distance_from_company')
                    ->label(__('attendance.fields.check_out_distance'))
                    ->formatStateUsing(fn (string $state): string => self::meters($state))
                    ->extraAttributes(self::TABULAR)
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('attendance_date')
                ->orderByDesc('check_in_at')
                ->orderByDesc('id'))
            // Offered, not imposed: the flat list stays the default, and an
            // administrator who wants a day at a glance groups by date and
            // reads each day's sessions together with their total.
            ->groups([
                Group::make('attendance_date')
                    ->label(__('attendance.groups.date'))
                    ->date()
                    ->collapsible(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('attendance.filters.employee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                // The state is a column on every row and was the one thing
                // on this table nobody could ask for. "Who is inside right
                // now" and "who never checked out" are the two questions an
                // administrator opens this screen with, and both are one
                // selection away here - which is also what the dashboard's
                // figures link to, so a number and the rows behind it agree.
                //
                // Derived, so the query is written out rather than compared
                // against a column: an open session is live on its own day
                // and a missing check-out on any earlier one. Both halves
                // lead with check_out_at and attendance_date, which is the
                // index this table already carries.
                SelectFilter::make('status')
                    ->label(__('attendance.filters.status'))
                    ->options(AttendanceStatus::options())
                    ->query(fn (Builder $query, array $data): Builder => self::withStatus($query, $data['value'] ?? null)),

                Filter::make('date')
                    ->label(__('attendance.filters.date'))
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('attendance.filters.date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['date'] ?? null),
                        fn (Builder $nested): Builder => $nested->where('attendance_date', $data['date']),
                    )),

                Filter::make('date_range')
                    ->label(__('attendance.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('attendance.filters.from')),
                        DatePicker::make('until')
                            ->label(__('attendance.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $nested): Builder => $nested->where('attendance_date', '>=', $data['from']),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $nested): Builder => $nested->where('attendance_date', '<=', $data['until']),
                        )),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('attendance.admin_actions.view')),
            ])
            // The row itself opens the session, not only the button at
            // the end of it. Six columns of Arabic run to 754px, so on a
            // tablet - or any window with the sidebar out - the table
            // scrolls sideways and that button is the first thing over
            // the edge. Clicking the row is what a reader tries first
            // anyway, and it is the same action, so nothing is lost when
            // the button cannot be reached.
            ->recordAction('view')
            ->paginated([25, 50, 100])
            // Six columns and a row action do not fit a phone, and this is
            // the screen an owner opens on one to see whether anybody is in
            // yet. Below the sm breakpoint each row becomes a labelled card
            // instead of a table the page has to be dragged sideways to
            // read - a sideways scroll also puts the row's own View button
            // off the edge, which is the whole way into the record.
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(__('attendance.empty.heading'))
            ->emptyStateDescription(__('attendance.empty.description'));
    }

    /**
     * The sessions in one of the three states, or every session when the
     * filter is unset or carries something that is not a state at all - a
     * hand-edited query string must narrow the list or leave it alone,
     * never empty it without saying why.
     *
     * @param  Builder<Attendance>  $query
     * @return Builder<Attendance>
     */
    private static function withStatus(Builder $query, mixed $value): Builder
    {
        $status = is_string($value) ? AttendanceStatus::tryFrom($value) : null;

        if (! $status instanceof AttendanceStatus) {
            return $query;
        }

        $today = app(AttendanceCalendar::class)->today()->toDateString();

        return match ($status) {
            AttendanceStatus::CheckedOut => $query->whereNotNull('check_out_at'),
            AttendanceStatus::CheckedIn => $query->open()->where('attendance_date', $today),
            AttendanceStatus::MissingCheckOut => $query->open()->where('attendance_date', '<', $today),
        };
    }

    /**
     * The badge glyph for each state, so the state survives being read by
     * somebody who cannot tell the green badge from the amber one.
     */
    private static function statusIcon(AttendanceStatus $status): BackedEnum
    {
        return match ($status) {
            AttendanceStatus::CheckedIn => Heroicon::OutlinedMapPin,
            AttendanceStatus::CheckedOut => Heroicon::OutlinedCheckCircle,
            AttendanceStatus::MissingCheckOut => Heroicon::OutlinedExclamationTriangle,
        };
    }

    private static function meters(string $meters): string
    {
        return __('attendance.units.meters', ['value' => Meters::format($meters)]);
    }
}
