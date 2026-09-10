<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Data\Requests\RequestNotice;
use Illuminate\Notifications\Notification;

/**
 * "Somebody has answered you", written into the bell of the employee who
 * asked.
 *
 * The same channel and the same reasons as NewRequestNotification beside it.
 * The one thing this carries that the other does not is the administrator's
 * note, and on a rejection that note is the whole of the answer: the request
 * changed nothing, and a refusal with no reason is indistinguishable from
 * being ignored.
 *
 * It does not replace the طلباتي page. That page still holds every request
 * and its state, and is still the place a decision can be read in full; this
 * is how somebody finds out there is something to read.
 */
final class RequestDecisionNotification extends Notification
{
    public function __construct(private readonly RequestNotice $notice) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->notice->toArray();
    }
}
