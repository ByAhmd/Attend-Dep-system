<?php

declare(strict_types=1);

namespace App\Filament\Employee\Actions;

use App\Data\Leave\LeaveDraft;
use App\Exceptions\Leave\LeaveRequestRefusedException;
use App\Filament\Employee\Contracts\ThrottlesRequests;
use App\Filament\Employee\Schemas\LeaveRequestForm;
use App\Models\User;
use App\Services\Leave\LeaveAttachmentStore;
use App\Services\Leave\LeaveRequestWorkflow;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * "Request leave", as a modal on whichever employee screen mounted it.
 *
 * The same four steps as the correction action beside it - throttle,
 * validate, call the service, repeat its refusal - with one addition: the
 * supporting document.
 *
 * The size and the MIME type are read back off the disk rather than taken
 * from the upload. The browser states both, and both are things a browser
 * may be wrong or lying about; what the disk holds is what an administrator
 * will actually be handed, so that is what the row records. The original
 * file name is the one value that can only come from the client, and it is
 * stored as a label and never as a path.
 */
final class RequestLeaveAction
{
    /**
     * The Livewire method name this action throttles under: its own bucket,
     * so leave requests and correction requests never spend each other's
     * allowance.
     */
    public const string NAME = 'requestLeave';

    public static function make(User $employee): Action
    {
        return Action::make(self::NAME)
            ->label(__('requests.tiles.leave'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->modalHeading(__('leave.employee.modal_heading'))
            ->modalDescription(__('leave.employee.modal_description'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('leave.employee.submit'))
            ->schema(fn (Schema $schema): Schema => LeaveRequestForm::configure($schema))
            ->action(function (array $data, Component&ThrottlesRequests $livewire) use ($employee): void {
                try {
                    $livewire->throttleRequest(self::NAME);
                } catch (TooManyRequestsException) {
                    self::refuse(__('requests.feedback.too_many_attempts'));
                }

                $draft = LeaveDraft::fromFormData(self::describeAttachment($data));

                try {
                    app(LeaveRequestWorkflow::class)->submit($employee, $draft);
                } catch (LeaveRequestRefusedException $exception) {
                    // The upload control wrote the file when the employee
                    // chose it, which was before the workflow had agreed to
                    // accept anything. A refusal must not leave those bytes
                    // on the disk with no row that will ever claim them.
                    if ($draft->attachmentPath !== null) {
                        app(LeaveAttachmentStore::class)->forget($draft->attachmentPath);
                    }

                    self::refuse($exception->getMessage());
                }

                Notification::make()
                    ->title(__('leave.employee.submitted'))
                    ->body(__('leave.employee.submitted_body', [
                        'from' => $draft->startsOn->format('Y-m-d'),
                        'until' => $draft->endsOn->format('Y-m-d'),
                    ]))
                    ->success()
                    ->send();

                $livewire->dispatch('leave-requested');
            });
    }

    /**
     * Completes the attachment group from the file that actually landed on
     * the disk.
     *
     * The four columns are written together or not at all, and the table
     * says so with a CHECK. A file the disk cannot describe is a file
     * nobody can be shown, so the whole group is dropped rather than written
     * half-filled - the request itself is unaffected, because the document
     * was always the optional part of it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function describeAttachment(array $data): array
    {
        $path = $data['attachment_path'] ?? null;

        if (! is_string($path) || $path === '') {
            return $data;
        }

        $disk = Storage::disk(LeaveAttachmentStore::DISK);
        $size = $disk->exists($path) ? $disk->size($path) : null;
        $mimeType = $size === null ? null : $disk->mimeType($path);

        if ($size === null || $mimeType === false || $mimeType === null) {
            return [...$data, 'attachment_path' => null, 'attachment_name' => null];
        }

        return [
            ...$data,
            'attachment_size' => $size,
            'attachment_mime_type' => $mimeType,
        ];
    }

    /**
     * Say what happened, keep the modal and everything in it, and write
     * nothing. Persistent, because this sentence is the whole answer.
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
