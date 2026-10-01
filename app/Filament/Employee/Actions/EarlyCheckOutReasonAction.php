<?php

declare(strict_types=1);

namespace App\Filament\Employee\Actions;

use App\Data\Attendance\EarlyCheckOutDraft;
use App\Enums\EarlyCheckOutReason;
use App\Models\AttendanceSetting;
use App\Services\Attendance\AttendanceCalendar;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\Width;
use Livewire\Component;

/**
 * "Why are you leaving early?", as a modal between the Check Out tap and
 * the location fix.
 *
 * The attendance page mounts this when the tap lands before the end of the
 * working day, and the modal's submit does not check anybody out: it hands
 * the reason back to the browser, which then takes the location fix and
 * calls the ordinary checkOut with the reason alongside the reading. The
 * order matters - reason first, fix second - so the fix is seconds old
 * when the workflow verifies it, not as old as the employee's typing.
 *
 * Every rule stays in the workflow. A tap that reaches the server after
 * the day has ended stores no reason however this modal was answered, and
 * a payload that skips the modal entirely is refused by the workflow's own
 * backstop. The form is two fields and lives here rather than in a
 * Schemas class: there is no second screen that could ever mount it.
 */
final class EarlyCheckOutReasonAction
{
    /**
     * The mountAction() name the page's Alpine component calls.
     */
    public const string NAME = 'earlyCheckOut';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->modalHeading(__('attendance.early.modal_heading'))
            // Resolved when the modal is described, not when the page
            // builds its actions, so requests that never open it skip the
            // settings read.
            ->modalDescription(fn (): string => __('attendance.early.modal_description', [
                'time' => AttendanceSetting::current()
                    ->workingHours()
                    ->endOn(app(AttendanceCalendar::class)->today())
                    ->format('H:i'),
            ]))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('attendance.early.submit'))
            ->schema([
                Select::make('reason')
                    ->label(__('attendance.early.reason'))
                    ->options(EarlyCheckOutReason::options())
                    ->required()
                    ->validationMessages([
                        'required' => __('attendance.early.reason_required'),
                    ])
                    ->native(false),

                Textarea::make('note')
                    ->label(__('attendance.early.note'))
                    ->rows(3)
                    ->maxLength(EarlyCheckOutDraft::NOTE_LIMIT),
            ])
            ->action(function (array $data, Component $livewire): void {
                // Back to the browser: the Alpine component listens for
                // this event and runs the ordinary check-out flow with the
                // reason riding along. The server sees the reason again
                // only inside that call, where the workflow decides
                // whether it is still needed.
                $livewire->dispatch(
                    'early-check-out-confirmed',
                    reason: $data['reason'],
                    note: $data['note'] ?? null,
                );
            });
    }
}
