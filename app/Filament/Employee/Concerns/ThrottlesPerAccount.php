<?php

declare(strict_types=1);

namespace App\Filament\Employee\Concerns;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;

/**
 * Rate limiting on the employee panel, counted per account rather than per
 * address.
 *
 * The package keys its limiter on the client IP, and a whole office reaches
 * this application from one public address: at eight o'clock the eleventh
 * person to arrive would be refused for the ten who checked in before them.
 * Every request that reaches these screens is authenticated, so the account
 * is the honest unit to count, and one employee tapping a button forty times
 * costs nobody else anything.
 *
 * Each caller passes its own method name, so every action gets its own
 * bucket: a day of presence pings never spends the check-in allowance, and a
 * flood of correction requests never locks anybody out of the two buttons
 * this product exists for.
 */
trait ThrottlesPerAccount
{
    use WithRateLimiting;

    /**
     * How many requests one account may send in a minute.
     *
     * Deliberately small. A correction or a leave request is a considered
     * act typed into a form, not something anybody does five times in a
     * minute by accident - and the one way to reach a form's submit without
     * reading it is to post at it.
     */
    private const int REQUEST_ATTEMPTS = 5;

    /**
     * The public face of the limiter, so a modal action defined outside this
     * component can spend the allowance the component owns. Declared by
     * ThrottlesRequests, which is what an action types itself against.
     *
     * The package's own rateLimit() is protected, and a modal form is a
     * class of its own by design; the alternative to this one method is an
     * action that does not throttle at all.
     *
     * @throws TooManyRequestsException
     */
    public function throttleRequest(string $method, int $attempts = self::REQUEST_ATTEMPTS): void
    {
        $this->rateLimit($attempts, method: $method);
    }

    /**
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function getRateLimitKey($method, $component = null): string
    {
        $component ??= static::class;

        return 'livewire-rate-limiter:'.sha1($component.'|'.$method.'|user:'.Filament::auth()->id());
    }
}
