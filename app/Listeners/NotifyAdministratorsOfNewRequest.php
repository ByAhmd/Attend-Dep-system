<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Data\Requests\RequestNotice;
use App\Events\RequestSubmitted;
use App\Notifications\NewRequestNotification;
use App\Services\Requests\RequestAudience;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Puts an arriving request in every administrator's bell.
 *
 * A listener rather than a call inside AttendanceCorrectionWorkflow, for the
 * same reason ActivateInvitedEmployee is one: telling somebody is a
 * consequence of the thing, not part of it. The workflow's job is to decide
 * whether a request may be filed and to file it; who hears about it changes
 * independently of that, and a service that knew both would have to be
 * reopened for either. Laravel discovers this from app/Listeners by its
 * handle() signature; there is no binding to keep in step.
 *
 * What this class does NOT do is defer itself with
 * ShouldHandleEventsAfterCommit, and that is worth stating because it is the
 * obvious way to write it. That contract hands the work to the transaction
 * manager, which runs it when the OUTERMOST transaction commits - and under
 * RefreshDatabase the outermost transaction is the test's own, which never
 * commits. The safeguard would therefore switch every notification in the
 * suite off while every test went on passing. The workflows dispatch after
 * DB::transaction() has returned instead, which is the same guarantee stated
 * in code that runs identically in a test and on the server.
 *
 * Every failure is swallowed and logged. An employee pressing Send is asking
 * for their request to be recorded, and it has been by the time this runs; a
 * `notifications` table that is missing, locked or full must not turn a
 * recorded request into an error message on their phone, because the request
 * is the thing that matters and the bell is not.
 */
final class NotifyAdministratorsOfNewRequest
{
    public function __construct(private readonly RequestAudience $audience) {}

    public function handle(RequestSubmitted $event): void
    {
        try {
            $administrators = $this->audience->administratorsOtherThan($event->request->user);

            if ($administrators->isEmpty()) {
                return;
            }

            Notification::send($administrators, new NewRequestNotification(RequestNotice::about($event->request)));
        } catch (Throwable $exception) {
            Log::warning('Administrators could not be notified of a new request.', [
                'request' => $event->request::class,
                'request_id' => $event->request->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
