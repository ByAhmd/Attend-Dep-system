<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\EnsureAccountIsActive;
use Filament\Http\Middleware\Authenticate;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Check In and Check Out are Livewire calls, and Livewire runs only the
 * middleware registered as persistent on those calls. The sign-out guard
 * must be on that list, or a deactivated employee pressing a button would
 * meet a bare 403 instead of being signed out as on a page load.
 */
final class PersistentSignOutTest extends TestCase
{
    #[Test]
    public function the_sign_out_guard_runs_on_livewire_updates_too(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(EnsureAccountIsActive::class, $persistent);
        $this->assertContains(Authenticate::class, $persistent);
    }
}
