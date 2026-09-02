<?php

declare(strict_types=1);

namespace App\Filament\Employee\Widgets;

use App\Models\Attendance;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/**
 * The signed-in employee's own attendance, newest day first.
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

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Attendance::query()
                    ->where('user_id', Filament::auth()->id())
                    ->orderByDesc('attendance_date'),
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

                TextColumn::make('status')
                    ->label(__('attendance.fields.status'))
                    ->badge()
                    ->state(fn (Attendance $record): string => $record->status()->label())
                    ->color(fn (Attendance $record): string => $record->status()->color()),
            ])
            // Four columns do not fit a phone; below the sm breakpoint each
            // row becomes a labelled card instead of a sideways scroll.
            ->stackedOnMobile()
            ->paginated([10])
            ->emptyStateHeading(__('attendance.history.empty_heading'))
            ->emptyStateDescription(__('attendance.history.empty_description'));
    }
}
