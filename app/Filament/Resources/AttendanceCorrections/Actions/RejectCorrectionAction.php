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
 * Refusing a correction.
 *
 * The same two blocks as the approval, because the facts do not change
 * because the answer is no, and a screen that showed less of them for a
 * refusal would be inviting a decision made on trust rather than on the
 * record.
 *
 * The note is required, and the service asserts it again. A rejection
 * writes nothing to the attendance row, so this sentence is the entire
 * answer the employee receives on the screen where they asked - a refusal
 * with no reason is indistinguishable from being ignored, and the employee
 * would simply ask again.
 */
final class RejectCorrectionAction
{
    public static function make(): Action
    {
        return Action::make('reject')
            ->label(__('corrections.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalIcon(Heroicon::OutlinedXCircle)
            ->modalIconColor('danger')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (AttendanceCorrection $record): string => __('corrections.actions.reject_heading', [
                'name' => $record->user->name,
            ]))
            ->modalDescription(__('corrections.helpers.reject_consequence'))
            ->modalSubmitActionLabel(__('corrections.actions.reject_confirm'))
            ->visible(fn (?Model $record): bool => AttendanceCorrectionResource::canDecide($record))
            ->schema([
                AttendanceCorrectionInfolist::request(),
                AttendanceCorrectionInfolist::comparison(),

                Textarea::make('decision_note')
                    ->label(__('corrections.fields.decision_note'))
                    ->placeholder(__('corrections.placeholders.note'))
                    ->helperText(__('corrections.helpers.decision_note_required'))
                    ->rows(3)
                    ->required()
                    ->maxLength(500)
                    ->validationMessages([
                        'required' => __('corrections.validation.decision_note_required'),
                        'max' => __('corrections.validation.note_max'),
                    ]),
            ])
            ->action(function (AttendanceCorrection $record, array $data): void {
                $administrator = Auth::user();

                // Unreachable from the panel; it keeps the workflow's
                // signature honest rather than handling a real case.
                if (! $administrator instanceof User) {
                    throw new Halt;
                }

                $note = $data['decision_note'] ?? null;

                try {
                    app(AttendanceCorrectionWorkflow::class)->reject(
                        $record,
                        $administrator,
                        is_string($note) ? $note : '',
                    );
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
                    ->title(__('corrections.notifications.rejected', ['name' => $record->user->name]))
                    ->body(__('corrections.notifications.rejected_body'))
                    ->success()
                    ->send();
            });
    }
}
