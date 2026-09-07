<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that carries an invitation link to a new employee.
 *
 * Not queued, matching Laravel's own ResetPassword notification: one
 * message sent in response to one deliberate action by an administrator,
 * who is watching the screen and is told immediately whether it left. A
 * queued invitation on a deployment with no worker would simply never
 * arrive, and the administrator would be told it had.
 *
 * The URL is built by the invitation service rather than here, so the one
 * link that is emailed is the same one the administrator can copy.
 */
final class EmployeeInvitationNotification extends Notification
{
    public function __construct(private readonly string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('invitations.mail.subject', ['company' => __('app.name')]))
            ->greeting(__('invitations.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('invitations.mail.intro', ['company' => __('app.name')]))
            ->action(__('invitations.mail.action'), $this->url)
            ->line(__('invitations.mail.expiry', [
                'minutes' => (int) config('auth.passwords.users.expire', 60),
            ]))
            ->line(__('invitations.mail.ignore'));
    }
}
