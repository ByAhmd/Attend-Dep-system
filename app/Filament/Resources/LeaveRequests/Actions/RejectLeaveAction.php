<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Actions;

use App\Exceptions\Leave\LeaveRequestRefusedException;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestInfolist;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveRequestWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Refusing leave.
 *
 * The same two blocks as the approval, for the same reason: the facts do
 * not change because the answer is no, and a refusal decided on less of
 * the record than an approval would be the wrong way round.
 *
 * The note is required and the service asserts it again. Nothing else
 * reaches the employee - no email is sent, and their own screen shows the
 * word "مرفوض" and this sentence beside it. A refusal with no reason is
 * indistinguishable from being ignored.
 */
final class RejectLeaveAction
{
    public static function make(): Action
    {
        return Action::make('reject')
            ->label(__('leave.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalIcon(Heroicon::OutlinedXCircle)
            ->modalIconColor('danger')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (LeaveRequest $record): string => __('leave.actions.reject_heading', [
                'name' => $record->user->name,
            ]))
            ->modalDescription(__('leave.helpers.reject_consequence'))
            ->modalSubmitActionLabel(__('leave.actions.reject_confirm'))
            ->visible(fn (?Model $record): bool => LeaveRequestResource::canDecide($record))
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        LeaveRequestInfolist::request(),
                        LeaveRequestInfolist::conflicts(),
                    ]),

                Textarea::make('decision_note')
                    ->label(__('leave.fields.decision_note'))
                    ->placeholder(__('leave.placeholders.reason'))
                    ->helperText(__('leave.helpers.decision_note_required'))
                    ->rows(3)
                    ->required()
                    ->maxLength(500)
                    ->validationMessages([
                        'required' => __('leave.validation.decision_note_required'),
                        'max' => __('leave.validation.reason_max', ['max' => 500]),
                    ]),
            ])
            ->action(function (LeaveRequest $record, array $data): void {
                $administrator = Auth::user();

                // Unreachable from the panel; it keeps the workflow's
                // signature honest rather than handling a real case.
                if (! $administrator instanceof User) {
                    throw new Halt;
                }

                $note = $data['decision_note'] ?? null;

                try {
                    app(LeaveRequestWorkflow::class)->reject(
                        $record,
                        $administrator,
                        is_string($note) ? $note : '',
                    );
                } catch (LeaveRequestRefusedException $exception) {
                    Notification::make()
                        ->title(__('leave.notifications.not_applied'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title(__('leave.notifications.rejected', ['name' => $record->user->name]))
                    ->body(__('leave.notifications.rejected_body'))
                    ->success()
                    ->send();
            });
    }
}
