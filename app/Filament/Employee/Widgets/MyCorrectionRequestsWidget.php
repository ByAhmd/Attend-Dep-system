<?php

declare(strict_types=1);

namespace App\Filament\Employee\Widgets;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use App\Models\AttendanceCorrection;
use Filament\Facades\Filament;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/**
 * The signed-in employee's own correction requests, newest first.
 *
 * The query is bound to the current account and to nothing else: no filter,
 * no search, no parameter through which another employee's requests could
 * be reached. The absence is the design, exactly as it is on the attendance
 * history table beside it.
 *
 * The decision note is a column and not a detail behind a click, because an
 * employee who was told no is owed the reason on the same screen as the
 * word "no".
 *
 * No row actions. There is no withdrawing a request: a mistaken one is
 * rejected by an administrator with a note, so every ending carries a name.
 */
#[On('correction-requested')]
final class MyCorrectionRequestsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the page rather than fetched afterwards: one indexed
     * lookup of a handful of rows costs far less than the extra round trip
     * deferring it would, and that trip is paid on a phone every time.
     */
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AttendanceCorrection::query()
                    ->forUser((int) Filament::auth()->id())
                    ->orderByDesc('submitted_at')
                    ->orderByDesc('id'),
            )
            ->heading(__('corrections.employee.heading_table'))
            ->columns([
                // The day leads the row - and titles the card once the
                // table stacks on a phone - so it carries the weight.
                TextColumn::make('attendance_date')
                    ->label(__('corrections.fields.attendance_date'))
                    ->date('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->weight(FontWeight::SemiBold),

                TextColumn::make('requested')
                    ->label(__('corrections.fields.requested'))
                    ->state(fn (AttendanceCorrection $record): string => $record->requestedRangeLabel())
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['dir' => 'ltr']),

                TextColumn::make('reason')
                    ->label(__('corrections.fields.reason'))
                    ->formatStateUsing(fn (CorrectionReason $state): string => $state->label())
                    ->color('gray')
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('requests.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())
                    ->icon(fn (RequestStatus $state): string => $state->icon())
                    ->color(fn (RequestStatus $state): string => $state->color()),

                TextColumn::make('decision_note')
                    ->label(__('requests.fields.decision_note'))
                    ->placeholder(__('requests.placeholders.no_note'))
                    ->wrap(),
            ])
            // Five columns do not fit a phone; below the sm breakpoint each
            // row becomes a labelled card instead of a sideways scroll.
            ->stackedOnMobile()
            ->striped()
            ->paginated([5, 25])
            ->emptyStateIcon('heroicon-o-pencil-square')
            ->emptyStateHeading(__('corrections.employee.empty_heading'))
            ->emptyStateDescription(__('corrections.employee.empty_description'));
    }
}
