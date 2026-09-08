<?php

declare(strict_types=1);

namespace App\Filament\Employee\Contracts;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;

/**
 * A page that spends a per-account rate-limit allowance on behalf of the
 * actions mounted on it.
 *
 * The two request actions are their own classes - a modal form is not a page
 * - and the limiter belongs to the Livewire component, whose rateLimit() is
 * protected. This interface is the narrow, named way an action reaches it,
 * so the action can be typed against a promise rather than against whichever
 * page happens to have mounted it.
 *
 * Implemented by ThrottlesPerAccount, which is where the counting is
 * explained.
 */
interface ThrottlesRequests
{
    /**
     * @throws TooManyRequestsException
     */
    public function throttleRequest(string $method, int $attempts = 5): void;
}
