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
 * Approving leave: the request on one side, what it runs into on the other.
 *
 * The description states what an approval does and, just as importantly,
 * what it does not. Leave records what was agreed; it does not suppress a
 * check-in, close a session or excuse a missing one, and an approver who
 * thought otherwise would be granting something this system cannot deliver.
 *
 * Recorded attendance inside the range is shown as a warning and never
 * refuses the approval - half a day worked before going home is ordinary,
 * and the system does not overrule the person who was there. Leave already
 * approved over the same days is refused, by the workflow, under a lock at
 * the moment of the decision: the conflicts block was true when the modal
 * opened, and two pending requests worked in the wrong order can still
 * become an overlap between one reading and the next.
 */
final class ApproveLeaveAction
{
    public static function make(): Action
    {
        return Action::make('approve')
            ->label(__('leave.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalIcon(Heroicon::OutlinedCheckCircle)
            ->modalIconColor('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (LeaveRequest $record): string => __('leave.actions.approve_heading', [
                'name' => $record->user->name,
            ]))
            ->modalDescription(__('leave.helpers.approve_consequence'))
            ->modalSubmitActionLabel(__('leave.actions.approve_confirm'))
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
                    ->helperText(__('leave.helpers.decision_note_optional'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (LeaveRequest $record, array $data): void {
                $administrator = Auth::user();

                // Unreachable from the panel; it keeps the workflow's
                // signature honest rather than handling a real case.
                if (! $administrator instanceof User) {
                    throw new Halt;
                }

                $note = $data['decision_note'] ?? null;
                $note = is_string($note) && trim($note) !== '' ? $note : null;

                try {
                    app(LeaveRequestWorkflow::class)->approve($record, $administrator, $note);
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
                    ->title(__('leave.notifications.approved', ['name' => $record->user->name]))
                    ->body(__('leave.notifications.approved_body', [
                        'from' => $record->starts_on->format('Y-m-d'),
                        'until' => $record->ends_on->format('Y-m-d'),
                    ]))
                    ->success()
                    ->send();
            });
    }
}
