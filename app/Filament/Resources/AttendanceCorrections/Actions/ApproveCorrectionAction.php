<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceCorrections\Actions;

use App\Exceptions\Attendance\AttendanceCorrectionRefusedException;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\AttendanceCorrections\Schemas\AttendanceCorrectionInfolist;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Approving a correction, which is the only act in this system that changes
 * an attendance row.
 *
 * The modal is therefore not a confirmation but the decision itself: the
 * request, and beside it what the device recorded against what is being
 * asked for, in the same components the record modal uses. Its description
 * states the consequence rather than asking "are you sure?" - the reader
 * knows they pressed Approve, and what they need told is that the device's
 * record is kept and the row is marked as corrected wherever it is read.
 *
 * The note is optional here and required on the rejection. An approval
 * gives the employee what they asked for and the record then explains
 * itself; a rejection gives them nothing, and the note is all they receive.
 *
 * A refusal from the workflow - the correction would open a second session,
 * the requester's account has been deleted, somebody answered it a moment
 * ago - raises the service's own sentence in the reader's language and
 * halts. The modal stays open with the note intact, the request stays
 * pending, and nothing was written: a decision the system could not carry
 * out is not a decision about the employee's claim, and must never be
 * recorded as one.
 */
final class ApproveCorrectionAction
{
    public static function make(): Action
    {
        return Action::make('approve')
            ->label(__('corrections.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalIcon(Heroicon::OutlinedCheckCircle)
            ->modalIconColor('success')
            // Wide enough for the two blocks to sit side by side from the
            // sm breakpoint up. Narrower and the comparison stacks on a
            // desktop, which is the one arrangement it must not have.
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (AttendanceCorrection $record): string => __('corrections.actions.approve_heading', [
                'name' => $record->user->name,
            ]))
            ->modalDescription(__('corrections.helpers.approve_consequence'))
            ->modalSubmitActionLabel(__('corrections.actions.approve_confirm'))
            ->visible(fn (?Model $record): bool => AttendanceCorrectionResource::canDecide($record))
            ->schema([
                AttendanceCorrectionInfolist::request(),
                AttendanceCorrectionInfolist::comparison(),

                Textarea::make('decision_note')
                    ->label(__('corrections.fields.decision_note'))
                    ->placeholder(__('corrections.placeholders.note'))
                    ->helperText(__('corrections.helpers.decision_note_optional'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (AttendanceCorrection $record, array $data): void {
                $administrator = Auth::user();

                // Unreachable from the panel, which no unauthenticated
                // request reaches; it is what keeps the workflow's signature
                // honest without a nullable administrator running through it.
                if (! $administrator instanceof User) {
                    throw new Halt;
                }

                // An empty textarea is no note at all rather than an empty
                // string: the column is nullable and the employee's screen
                // prints "لا توجد ملاحظة" for a null, not a blank line.
                $note = $data['decision_note'] ?? null;
                $note = is_string($note) && trim($note) !== '' ? $note : null;

                try {
                    app(AttendanceCorrectionWorkflow::class)->approve($record, $administrator, $note);
                } catch (AttendanceCorrectionRefusedException $exception) {
                    Notification::make()
                        ->title(__('corrections.notifications.not_applied'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title(__('corrections.notifications.approved', ['name' => $record->user->name]))
                    ->body(__('corrections.notifications.approved_body', [
                        'date' => $record->attendance_date->format('Y-m-d'),
                    ]))
                    ->success()
                    ->send();
            });
    }
}
