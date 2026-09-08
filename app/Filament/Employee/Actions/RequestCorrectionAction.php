<?php

declare(strict_types=1);

namespace App\Filament\Employee\Actions;

use App\Data\Attendance\CorrectionDraft;
use App\Exceptions\Attendance\AttendanceCorrectionRefusedException;
use App\Filament\Employee\Contracts\ThrottlesRequests;
use App\Filament\Employee\Schemas\CorrectionRequestForm;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionWorkflow;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * "Fix a record", as a modal on whichever employee screen mounted it.
 *
 * Four steps, in the same order and for the same reasons as the check-in
 * button on the attendance page: throttle, validate, call the service, turn
 * the domain's refusal into a sentence. The throttle comes first so a flood
 * of malformed payloads costs exactly what a flood of well-formed ones does.
 *
 * The service is the authority on every rule. This class knows only how to
 * ask it and how to repeat its answer, which is why a refusal keeps the
 * modal open with everything the employee typed still in it: they are being
 * told what is wrong, not being sent back to the start.
 *
 * The success notification names the day back to the employee. A request
 * about the wrong date is the mistake this form makes, and the fastest place
 * to notice it is the confirmation.
 */
final class RequestCorrectionAction
{
    /**
     * The Livewire method name this action throttles under, so its allowance
     * is its own and a burst of correction requests never stands between
     * anybody and the two buttons this product exists for.
     */
    public const string NAME = 'requestCorrection';

    public static function make(User $employee): Action
    {
        return Action::make(self::NAME)
            ->label(__('requests.tiles.correction'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('corrections.employee.modal_heading'))
            ->modalDescription(__('corrections.employee.modal_description'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('corrections.employee.submit'))
            // No allowance means no form: the modal opens onto the sentence
            // that explains why, and with nothing to press. A submit button
            // over a form that cannot be sent is an invitation to try.
            ->modalSubmitAction(fn (): ?bool => CorrectionRequestForm::isOpenTo($employee) ? null : false)
            ->schema(fn (Schema $schema): Schema => CorrectionRequestForm::configure($schema, $employee))
            ->action(function (array $data, Component&ThrottlesRequests $livewire) use ($employee): void {
                try {
                    $livewire->throttleRequest(self::NAME);
                } catch (TooManyRequestsException) {
                    self::refuse(__('requests.feedback.too_many_attempts'));
                }

                $draft = CorrectionDraft::fromFormData($data);

                try {
                    app(AttendanceCorrectionWorkflow::class)->submit($employee, $draft);
                } catch (AttendanceCorrectionRefusedException $exception) {
                    self::refuse($exception->getMessage());
                }

                Notification::make()
                    ->title(__('corrections.employee.submitted'))
                    ->body(__('corrections.employee.submitted_body', [
                        'date' => $draft->date->format('Y-m-d'),
                    ]))
                    ->success()
                    ->send();

                // The employee's own list of requests is a separate Livewire
                // component; tell it a row appeared, so the confirmation and
                // the table below it agree without a reload.
                $livewire->dispatch('correction-requested');
            });
    }

    /**
     * Say what happened, keep the modal and everything in it, and write
     * nothing.
     *
     * Persistent because the sentence is the whole of what the employee
     * receives, and a toast that fades in four seconds is a rule nobody
     * read.
     */
    private static function refuse(string $message): never
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->persistent()
            ->send();

        throw new Halt;
    }
}
