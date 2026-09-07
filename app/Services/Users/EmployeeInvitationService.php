<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Notifications\EmployeeInvitationNotification;
use App\Support\Filament\PanelAccess;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Issues the invitation that lets a new employee choose their own password.
 *
 * The token is Laravel's password-reset token, so expiry
 * (config/auth.php passwords.users.expire) and throttling are the
 * framework's job and nothing here generates or stores a secret. The link
 * points at the employee panel's own reset screen, which brings the
 * validation, the rate limiting and the layout with it.
 *
 * Delivery is best effort by design: this deployment may run with no
 * outbound mail at all, and an administrator must still be able to onboard
 * someone. Whatever happens to the email, the caller gets the link.
 */
final readonly class EmployeeInvitationService
{
    /**
     * Mailers that accept a message and deliver it to nobody. Sending
     * through one of these is not a failure, but telling an administrator
     * "the email is on its way" would be a lie, so it is not attempted.
     */
    private const array UNDELIVERABLE_MAILERS = ['log', 'array', 'null'];

    /**
     * Issue an invitation and try to email it.
     *
     * Safe to call again at any time: the broker replaces any outstanding
     * token for that address, so an earlier link stops working the moment a
     * new one is issued and two live invitations can never exist at once.
     */
    public function invite(User $employee): Invitation
    {
        $url = $this->issueLink($employee);

        return new Invitation($url, $this->deliver($employee, $url));
    }

    /**
     * The link on its own, for an administrator who will pass it along by
     * hand. It replaces the previous one exactly as invite() does - there
     * is only ever one live invitation per address.
     */
    public function issueLink(User $employee): string
    {
        $token = Password::broker()->createToken($employee);

        return Filament::getPanel(PanelAccess::EMPLOYEE_PANEL_ID)
            ->getResetPasswordUrl($token, $employee);
    }

    /**
     * Whether an invitation may be issued for this account.
     *
     * Only while it is still waiting for its first password. An active
     * employee who forgot theirs is served by the reset-password action, and
     * a deactivated account must not be handed a route back in.
     */
    public function canBeInvited(User $employee): bool
    {
        return $employee->status->isPending();
    }

    /**
     * Attempt the email, and answer whether it left the application.
     *
     * A mail failure must never reach the caller: the account exists, the
     * link is valid, and an exception here would leave the administrator
     * looking at an error page for an invitation that in fact worked.
     */
    private function deliver(User $employee, string $url): bool
    {
        if (! $this->mailerDelivers()) {
            return false;
        }

        try {
            $employee->notify(
                (new EmployeeInvitationNotification($url))->locale((string) config('app.locale')),
            );

            return true;
        } catch (Throwable $exception) {
            Log::error('The invitation email could not be sent.', [
                'user_id' => $employee->getKey(),
                'exception' => $exception,
            ]);

            return false;
        }
    }

    private function mailerDelivers(): bool
    {
        return ! in_array((string) config('mail.default'), self::UNDELIVERABLE_MAILERS, true);
    }
}
