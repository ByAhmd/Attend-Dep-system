<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Data\Requests\RequestNotice;
use Illuminate\Notifications\Notification;

/**
 * "An employee has asked for something", written into an administrator's
 * bell.
 *
 * The database channel and nothing else. There is no mail here on purpose:
 * this host has no queue worker and, in all likelihood, no configured
 * mailer, so a request announced by email would be a request announced
 * nowhere - and an administrator told by two channels reads it once and
 * dismisses the other.
 *
 * Not queued, for the same reason EmployeeInvitationNotification is not: the
 * queue runs synchronously here, so ShouldQueue would buy a job wrapper
 * around an INSERT and nothing else. Filament's own DatabaseNotification is
 * queued, which is one of the two reasons this class exists rather than
 * Notification::make()->sendToDatabase(); the other is that it stores the
 * notice's data instead of a rendered Arabic or English sentence.
 */
final class NewRequestNotification extends Notification
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
