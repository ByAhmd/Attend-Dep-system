<?php

declare(strict_types=1);

namespace App\Filament\Employee\Widgets;

use App\Models\Attendance;
use App\Support\Attendance\SessionDuration;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
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
            ->description(__('attendance.history.description'))
            ->columns([
                // The date leads each row - and, once the table stacks into
                // cards on a phone, titles it - so it carries the weight.
                TextColumn::make('attendance_date')
                    ->label(__('attendance.fields.date'))
                    ->date('Y-m-d')
                    ->weight(FontWeight::Medium),

                // A corrected time is marked here in exactly the words the
                // administrator's list uses, and with the moment the device
                // recorded printed underneath it. The employee is the person
                // with the most reason to know which of their times a
                // machine saw and which one was granted: reading it here and
                // reading it there must never be two different stories.
                TextColumn::make('check_in_at')
                    ->label(__('attendance.fields.check_in_at'))
                    ->time('H:i')
                    ->icon(fn (Attendance $record): ?BackedEnum => $record->isCheckInCorrected()
                        ? Heroicon::OutlinedPencilSquare
                        : null)
                    ->iconPosition(IconPosition::Before)
                    ->color(fn (Attendance $record): ?string => $record->isCheckInCorrected() ? 'info' : null)
                    ->description(fn (Attendance $record): ?string => self::correctedWord(
                        $record->isCheckInCorrected(),
                        $record->hasDeviceCheckIn(),
                    ), position: 'above')
                    ->description(fn (Attendance $record): ?string => self::deviceMoment(
                        $record->isCheckInCorrected(),
                        $record->hasDeviceCheckIn(),
                        $record->deviceCheckInAt(),
                    )),

                TextColumn::make('check_out_at')
                    ->label(__('attendance.fields.check_out_at'))
                    ->time('H:i')
                    ->placeholder(__('attendance.placeholders.no_check_out'))
                    ->icon(fn (Attendance $record): ?BackedEnum => $record->isCheckOutCorrected()
                        ? Heroicon::OutlinedPencilSquare
                        : null)
                    ->iconPosition(IconPosition::Before)
                    ->color(fn (Attendance $record): ?string => $record->isCheckOutCorrected() ? 'info' : null)
                    ->description(fn (Attendance $record): ?string => self::correctedWord(
                        $record->isCheckOutCorrected(),
                        $record->hasDeviceCheckOut(),
                    ), position: 'above')
                    ->description(fn (Attendance $record): ?string => self::deviceMoment(
                        $record->isCheckOutCorrected(),
                        $record->hasDeviceCheckOut(),
                        $record->deviceCheckOutAt(),
                    )),

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
            // Ten near-identical rows of times are easy to lose one's place
            // in; the banding is the cheapest way to keep a row together
            // while the eye crosses it.
            ->striped()
            ->paginated([10])
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('attendance.history.empty_heading'))
            ->emptyStateDescription(__('attendance.history.empty_description'));
    }

    /**
     * Which of the two things happened to this moment, or nothing at all.
     *
     * The same two words, chosen the same way, as the administrator's list:
     * مصحَّح for a time the device recorded and an approved correction
     * moved, مسجَّل يدويًا for a time the device never recorded at all. The
     * employee has more reason than anybody to know which of their own times
     * a machine saw, and the answer they read here has to be the answer the
     * administrator reads there.
     */
    private static function correctedWord(bool $isCorrected, bool $hasDeviceRecord): ?string
    {
        if (! $isCorrected) {
            return null;
        }

        return $hasDeviceRecord
            ? __('attendance.badges.corrected')
            : __('attendance.badges.recorded_manually');
    }

    /**
     * What the device recorded before the correction moved it, under the
     * moment that replaced it. Nothing where the device recorded nothing:
     * the word above has already said so.
     */
    private static function deviceMoment(bool $isCorrected, bool $hasDeviceRecord, ?CarbonImmutable $moment): ?string
    {
        if (! $isCorrected || ! $hasDeviceRecord || $moment === null) {
            return null;
        }

        return __('attendance.badges.corrected_from', ['time' => $moment->format('H:i')]);
    }
}
