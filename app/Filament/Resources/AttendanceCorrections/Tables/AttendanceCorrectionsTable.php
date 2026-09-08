<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceCorrections\Tables;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use App\Filament\Resources\AttendanceCorrections\Actions\ApproveCorrectionAction;
use App\Filament\Resources\AttendanceCorrections\Actions\RejectCorrectionAction;
use App\Models\AttendanceCorrection;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The correction queue: what is waiting, oldest wait first.
 *
 * Every other list in this panel is a log and reads newest first. This one
 * is a queue and reads the other way round, because a queue is served in
 * the order people joined it - the request nobody has answered for five
 * days is the one that matters, and newest-first would bury it. The list
 * page says so out loud in its subheading, so the reversal is not something
 * a reader has to notice.
 *
 * It opens filtered to what is pending, with a cross to clear the filter.
 * The queue is what an administrator comes here for; the decided requests
 * are a record and are one click away.
 *
 * There are no bulk actions and there never will be. Twelve requests are
 * twelve decisions, each one an amendment to somebody's attendance record,
 * and a checkbox column that could approve them together would be the one
 * control in this product capable of rewriting a dozen days by accident.
 *
 * The reason rides under the name rather than taking a column of its own:
 * "نسيان تسجيل الحضور أو الانصراف" is nine words wide and, given a column,
 * pushes the row past the 625px this table has at 1024px with the sidebar
 * pinned open - which is the width the row's own action menu falls off at.
 */
final class AttendanceCorrectionsTable
{
    /**
     * A pair of clock times joined by an arrow: digits and neutral
     * characters only, so the bidirectional algorithm would otherwise print
     * the check-out first in the Arabic interface. Filament's numeric class
     * lines the figures up down the column.
     *
     * @var array<string, string>
     */
    private const TABULAR_LTR = ['dir' => 'ltr', 'class' => 'fi-numeric'];

    public static function configure(Table $table): Table
    {
        return $table
            // Mandatory. Every row names its employee, so without the eager
            // load this list is one query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                // Wrapped, so the reason under the name cannot set the
                // table's width: "The internet connection dropped" on one
                // unbroken line pushes this table past the 640px it has at
                // 1024px with the sidebar pinned open, and what goes over
                // the edge first is the row's own action menu.
                TextColumn::make('user.name')
                    ->label(__('corrections.fields.employee'))
                    ->description(fn (AttendanceCorrection $record): string => $record->reason->label())
                    ->weight(FontWeight::SemiBold)
                    ->wrap()
                    ->grow()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('attendance_date')
                    ->label(__('corrections.fields.attendance_date'))
                    ->date('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                // Composed on the loaded row from the day and the two
                // wall-clock times, never queried: an em dash stands for the
                // half of the day the employee is not asking to change.
                TextColumn::make('requested')
                    ->label(__('corrections.fields.requested'))
                    ->state(fn (AttendanceCorrection $record): string => $record->requestedRangeLabel())
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(self::TABULAR_LTR),

                TextColumn::make('status')
                    ->label(__('corrections.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())
                    ->icon(fn (RequestStatus $state): string => $state->icon())
                    ->color(fn (RequestStatus $state): string => $state->color()),

                // Behind the column menu, for the same reason the two
                // distances are on the attendance list: it is the audit
                // rather than the sentence. The order of the queue already
                // says which request has waited longest, the subheading
                // says so in words, and the record modal prints the moment
                // in full. Kept visible it cost 116px on a table that has
                // 640px at 1024px with the sidebar pinned open, which is
                // where the row's own action menu goes over the edge.
                TextColumn::make('submitted_at')
                    ->label(__('corrections.fields.submitted_at'))
                    ->dateTime('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('submitted_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('corrections.filters.status'))
                    ->options(RequestStatus::options())
                    ->default(RequestStatus::Pending->value),

                SelectFilter::make('user_id')
                    ->label(__('corrections.filters.employee'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reason')
                    ->label(__('corrections.filters.reason'))
                    ->options(CorrectionReason::options()),

                // A request from a deleted account cannot be decided - the
                // workflow refuses to write attendance for one - so it is
                // out of the queue by default rather than sitting in it
                // offering buttons that will be refused. Clearing the filter
                // shows every request, deleted accounts included, because
                // the record of what was asked outlives the account.
                TernaryFilter::make('deleted_employee')
                    ->label(__('corrections.filters.deleted_employee'))
                    ->placeholder(__('corrections.filters.deleted_employee_with'))
                    ->trueLabel(__('corrections.filters.deleted_employee_only'))
                    ->falseLabel(__('corrections.filters.deleted_employee_without'))
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

                Filter::make('date_range')
                    ->label(__('corrections.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('corrections.filters.from')),
                        DatePicker::make('until')
                            ->label(__('corrections.filters.until')),
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
                // One menu rather than three labelled buttons: "فتح الطلب /
                // اعتماد / رفض" is 300px of Arabic on a table that has 625px
                // at 1024px with the sidebar out, and Approve and Reject are
                // absent on a decided row - a row whose buttons move about
                // is read wrong.
                ActionGroup::make([
                    ViewAction::make()
                        ->label(__('corrections.actions.view'))
                        ->modalHeading(fn (AttendanceCorrection $record): string => __('corrections.navigation.model').' — '.$record->user->name)
                        // The decision is offered from the record modal too,
                        // which is where it is most likely to be taken:
                        // somebody who has just read the comparison should
                        // not have to close it to answer.
                        ->extraModalFooterActions([
                            ApproveCorrectionAction::make()->cancelParentActions(),
                            RejectCorrectionAction::make()->cancelParentActions(),
                        ]),

                    ApproveCorrectionAction::make(),
                    RejectCorrectionAction::make(),
                ]),
            ])
            // The row opens the request. At 1024px with the sidebar pinned
            // the table scrolls sideways and the action menu is the first
            // thing over the edge, and clicking the row is what a reader
            // tries first anyway.
            ->recordAction('view')
            ->paginated([25, 50, 100])
            // Five columns and a menu do not fit a phone. Below the sm
            // breakpoint each request becomes a labelled card, so the day
            // and the times asked for are read together.
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedInboxStack)
            ->emptyStateHeading(__('corrections.empty.heading'))
            ->emptyStateDescription(__('corrections.empty.description'));
    }
}
