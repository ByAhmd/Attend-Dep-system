<?php

declare(strict_types=1);

namespace App\Filament\Employee\Widgets;

use App\Models\Attendance;
use App\Support\Attendance\SessionDuration;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/**
 * The signed-in employee's own sessions, newest first.
 *
 * One row is one session, so a day on which the employee left and came back
 * appears as the two sessions it was; ordering by the check-in within the
 * day keeps them in the order they happened, latest at the top.
 *
 * The query is bound to the current account and nothing else: there is no
 * filter, search or parameter through which another employee's records could
 * be reached, which is what the policy promises and what this widget keeps.
 *
 * A nested Livewire component of the page, so it re-queries on the event
 * the page dispatches after a successful check-in or check-out - otherwise
 * "Check-in successful" would sit above a history that still lacks today.
 */
#[On('attendance-recorded')]
final class AttendanceHistoryWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the page, not fetched afterwards.
     *
     * Filament defers a widget by default, which costs a second HTTP request
     * once the page has painted. That trade is worth it for a widget whose
     * query is slow; this one is a single indexed lookup of ten rows, so the
     * round trip costs far more than the query it defers - and it is paid on
     * a phone, on mobile data, every time an employee opens the screen.
     */
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Attendance::query()
                    ->forUser((int) Filament::auth()->id())
                    ->orderByDesc('attendance_date')
                    ->orderByDesc('check_in_at')
                    ->orderByDesc('id'),
            )
            ->heading(__('attendance.history.heading'))
            ->columns([
                TextColumn::make('attendance_date')
                    ->label(__('attendance.fields.date'))
                    ->date('Y-m-d'),

                TextColumn::make('check_in_at')
                    ->label(__('attendance.fields.check_in_at'))
                    ->time('H:i'),

                TextColumn::make('check_out_at')
                    ->label(__('attendance.fields.check_out_at'))
                    ->time('H:i')
                    ->placeholder(__('attendance.placeholders.no_check_out')),

                TextColumn::make('duration')
                    ->label(__('attendance.fields.duration'))
                    ->state(fn (Attendance $record): string => SessionDuration::format($record->durationInSeconds())),

                TextColumn::make('status')
                    ->label(__('attendance.fields.status'))
                    ->badge()
                    ->state(fn (Attendance $record): string => $record->status()->label())
                    ->color(fn (Attendance $record): string => $record->status()->color()),
            ])
            // Five columns do not fit a phone; below the sm breakpoint each
            // row becomes a labelled card instead of a sideways scroll.
            ->stackedOnMobile()
            ->paginated([10])
            ->emptyStateHeading(__('attendance.history.empty_heading'))
            ->emptyStateDescription(__('attendance.history.empty_description'));
    }
}
