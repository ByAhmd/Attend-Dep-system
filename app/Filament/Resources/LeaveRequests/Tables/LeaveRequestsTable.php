<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Tables;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Filament\Resources\LeaveRequests\Actions\ApproveLeaveAction;
use App\Filament\Resources\LeaveRequests\Actions\RejectLeaveAction;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * The leave queue: what is waiting, oldest wait first.
 *
 * The same shape as the correction queue, verb for verb, because they are
 * answered by the same person in the same sitting and a second arrangement
 * of the same job would be a second thing to learn.
 *
 * The period is one column and not two. "من / إلى" as separate columns
 * costs 180px on a table that has 625px at 1024px with the sidebar pinned
 * open, and nobody reads a start date without its end date anyway.
 *
 * The day count is derived in PHP from two dates already on the row - never
 * a DATEDIFF per row - and the summary does the same subtraction in SQL for
 * whatever the filters have selected. It is the one figure here that
 * carries an Arabic word, so it stays in the interface face: a monospaced
 * stack has no Arabic in it and the browser would draw "أيام" from
 * whatever it found instead.
 */
final class LeaveRequestsTable
{
    /**
     * A date range joined by an arrow is digits and neutral characters, so
     * without this the bidirectional algorithm prints the end date first.
     *
     * @var array<string, string>
     */
    private const TABULAR_LTR = ['dir' => 'ltr', 'class' => 'fi-numeric'];

    public static function configure(Table $table): Table
    {
        return $table
            // Mandatory: every row names its employee.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                // Wrapped rather than allowed to set the table's width. The
                // leave type rides under the name, and "Bereavement -
                // spouse, parent or child" on one unbroken line pushed this
                // table 23px past the 640px it has at 1024px with the
                // sidebar open, which clips the row's own action menu. A
                // name that takes two lines costs a reader nothing; a
                // decision they cannot reach costs them the decision.
                TextColumn::make('user.name')
                    ->label(__('leave.fields.employee'))
                    ->description(fn (LeaveRequest $record): string => $record->type->label())
                    ->weight(FontWeight::SemiBold)
                    ->wrap()
                    ->grow()
                    ->searchable()
                    ->sortable(),

                // The period and its length in one column, the length
                // riding under the dates the way the leave type rides
                // under the name.
                //
                // Measured rather than guessed: as a column of its own the
                // day count took 93px, and at 1024px with the sidebar
                // pinned open this table has 640px - so five columns ran to
                // 670px and clipped half of the row's own action menu,
                // which is where Approve and Reject live. Nobody reads a
                // span without the dates that produced it anyway.
                //
                // The figure keeps the interface face rather than the
                // monospaced one: its unit is an Arabic word, and no
                // monospaced stack has an Arabic glyph to draw it with. It
                // also carries its own dir="rtl", because the cell around
                // it is pinned left-to-right for the dates and an Arabic
                // phrase inheriting that would print "7" before "أيام"
                // instead of after it.
                TextColumn::make('starts_on')
                    ->label(__('leave.fields.period'))
                    ->state(fn (LeaveRequest $record): string => $record->starts_on->format('Y-m-d')
                        .' → '
                        .$record->ends_on->format('Y-m-d'))
                    ->description(fn (LeaveRequest $record): Htmlable => self::dayCount($record))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(self::TABULAR_LTR)
                    ->sortable()
                    ->summarize(
                        Summarizer::make('total_days')
                            ->label(__('leave.summaries.total_days'))
                            // Both ends inclusive, exactly as dayCount() is
                            // in PHP, so the total of a filtered list and
                            // the figures it totals cannot disagree.
                            ->using(fn (QueryBuilder $query): string => trans_choice(
                                'leave.units.days',
                                (int) $query->sum(DB::raw('DATEDIFF(ends_on, starts_on) + 1')),
                            )),
                    ),

                TextColumn::make('status')
                    ->label(__('leave.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())
                    ->icon(fn (RequestStatus $state): string => $state->icon())
                    ->color(fn (RequestStatus $state): string => $state->color()),

                // Behind the column menu, as on the correction queue: the
                // order of the list says which request has waited longest,
                // and 116px of submission date is what pushes this table
                // off the 640px it has at 1024px with the sidebar open.
                TextColumn::make('submitted_at')
                    ->label(__('leave.fields.submitted_at'))
                    ->dateTime('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('submitted_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('leave.filters.status'))
                    ->options(RequestStatus::options())
                    ->default(RequestStatus::Pending->value),

                SelectFilter::make('user_id')
                    ->label(__('leave.filters.employee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('type')
                    ->label(__('leave.filters.type'))
                    ->options(LeaveType::options()),

                // See the correction queue for why a deleted requester is
                // out of the list by default and back in it when the filter
                // is cleared.
                TernaryFilter::make('deleted_employee')
                    ->label(__('leave.filters.deleted_employee'))
                    ->placeholder(__('leave.filters.deleted_employee_with'))
                    ->trueLabel(__('leave.filters.deleted_employee_only'))
                    ->falseLabel(__('leave.filters.deleted_employee_without'))
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas(
                            'user',
                            fn (Builder $account): Builder => $account->whereNotNull('deleted_at'),
                        ),
                        false: fn (Builder $query): Builder => $query->whereHas(
                            'user',
                            fn (Builder $account): Builder => $account->whereNull('deleted_at'),
                        ),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                // Interval semantics, not containment: a fortnight that
                // began before the window and runs into it is a request
                // about those days and must be found by asking for them.
                // Comparing starts_on alone would hide exactly the long
                // absences somebody scanning a month needs to see.
                Filter::make('period')
                    ->label(__('leave.filters.period'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('leave.filters.from')),
                        DatePicker::make('until')
                            ->label(__('leave.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $nested): Builder => $nested->where('ends_on', '>=', $data['from']),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $nested): Builder => $nested->where('starts_on', '<=', $data['until']),
                        )),

                // Approved only, because "on leave today" is a statement of
                // fact and a pending request is a question. It therefore
                // says nothing while the status filter is left at its
                // pending default - both indicators are on screen with a
                // cross apiece, so the empty list explains itself.
                Filter::make('on_leave_today')
                    ->label(__('leave.filters.on_leave_today'))
                    ->query(fn (Builder $query): Builder => self::onLeaveToday($query)),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label(__('leave.actions.view'))
                        ->modalHeading(fn (LeaveRequest $record): string => __('leave.navigation.model').' — '.$record->user->name)
                        ->extraModalFooterActions([
                            ApproveLeaveAction::make()->cancelParentActions(),
                            RejectLeaveAction::make()->cancelParentActions(),
                        ]),

                    ApproveLeaveAction::make(),
                    RejectLeaveAction::make(),
                ]),
            ])
            ->recordAction('view')
            ->paginated([25, 50, 100])
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedInboxStack)
            ->emptyStateHeading(__('leave.empty.heading'))
            ->emptyStateDescription(__('leave.empty.description'));
    }

    /**
     * How many days the request covers, in its own reading direction.
     *
     * The surrounding cell is pinned left-to-right so the two dates keep
     * their order, and an Arabic phrase inside a left-to-right element is
     * laid out from the left: "7 أيام" would be drawn with the figure
     * before the word, which is the wrong way round for the reader. The
     * span puts the phrase back into its own paragraph direction; the text
     * is escaped, so a translation is markup to nobody.
     */
    private static function dayCount(LeaveRequest $record): Htmlable
    {
        return new HtmlString(
            '<span dir="rtl">'.e(trans_choice('leave.units.days', $record->dayCount())).'</span>',
        );
    }

    /**
     * Leave that has actually been agreed for today, from the model's own
     * scope, so the list and anything else that asks "who is off today"
     * cannot answer it two different ways.
     *
     * Today comes from AttendanceCalendar and never from the database
     * server's idea of the date: every other "today" in this system is the
     * Riyadh one, and a filter that used another would be off by a day for
     * three hours every night.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private static function onLeaveToday(Builder $query): Builder
    {
        return $query->approvedOn(app(AttendanceCalendar::class)->today());
    }
}
