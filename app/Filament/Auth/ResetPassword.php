<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The screen an invited employee lands on to choose their first password.
 *
 * Filament's own reset page refuses anyone who cannot already enter the
 * panel, which is exactly the account being invited: it is Pending, so
 * UserStatus::canAuthenticate() says no and PanelAccess turns it away. Left
 * alone, accepting an invitation would fail and burn the token with it -
 * the broker deletes a token once it has been spent, successfully or not.
 *
 * So resetPassword() is reimplemented with one difference from the parent:
 * the account may also be one that has been invited and has not chosen a
 * password yet. Everything else - the two rate limits, the form, the
 * validation, the notifications, the response - is the parent's. A
 * deactivated account is still refused; deactivation must not be undone by
 * an old link.
 */
final class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->isResetPasswordRateLimited($this->email)) {
            return null;
        }

        $data = $this->form->getState();

        $data['email'] = $this->email;
        $data['token'] = $this->token;

        $isPermitted = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$isPermitted): void {
                if (! $this->maySetPassword($user)) {
                    $isPermitted = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                // ActivateInvitedEmployee listens for this and promotes a
                // pending account to active.
                event(new PasswordResetEvent($user));
            },
        );

        if (! $isPermitted) {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()
                ->title(__($status))
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()
            ->title(__($status))
            ->danger()
            ->send();

        return null;
    }

    /**
     * Whether this account is allowed to set a password through this screen.
     *
     * An invited account cannot enter the panel yet - that is what the
     * invitation is for - so it is admitted on the strength of being
     * Pending. Everyone else answers to the panel guard as before.
     */
    private function maySetPassword(CanResetPassword|Model|Authenticatable $user): bool
    {
        if ($user instanceof User && $user->status->isPending()) {
            return true;
        }

        if (! $user instanceof FilamentUser) {
            return true;
        }

        return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
    }
}
