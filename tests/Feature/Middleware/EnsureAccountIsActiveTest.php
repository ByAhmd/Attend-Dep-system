<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\UserStatus;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Support\Filament\PanelAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The middleware that ends the session of an account deactivated while it
 * was signed in. It runs ahead of Filament's Authenticate on both panels,
 * so the person is signed out and told why rather than left on a 403 with
 * a live session behind it.
 */
final class EnsureAccountIsActiveTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(PanelAccess::ADMIN_PANEL_ID);
    }

    #[Test]
    public function an_active_account_passes_through_untouched(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $request = $this->requestWithSession();
        $expected = new Response('next');

        $response = (new EnsureAccountIsActive)->handle($request, static fn (Request $request): Response => $expected);

        $this->assertSame($expected, $response);
        $this->assertAuthenticatedAs($admin);
    }

    #[Test]
    public function a_guest_is_left_for_the_authentication_middleware(): void
    {
        $request = $this->requestWithSession();
        $expected = new Response('next');

        $response = (new EnsureAccountIsActive)->handle($request, static fn (Request $request): Response => $expected);

        $this->assertSame($expected, $response);
        $this->assertGuest();
    }

    #[Test]
    public function an_account_deactivated_mid_session_is_signed_out_and_sent_to_the_login_page(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);
        $request = $this->requestWithSession();
        $session = $request->session();
        $session->put('remembered', 'something from earlier in the session');
        $originalId = $session->getId();
        $originalToken = $session->token();

        $employee->forceFill(['status' => UserStatus::Inactive])->save();

        $response = (new EnsureAccountIsActive)->handle($request, static function (): Response {
            throw new \LogicException('The next middleware must not run for a deactivated account.');
        });

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(Filament::getLoginUrl(), $response->getTargetUrl());
        $this->assertStringContainsString('/admin/login', $response->getTargetUrl());
        $this->assertGuest();

        // Invalidated, not merely emptied: a new id and a new CSRF token, so
        // nothing the old session knew can be replayed.
        $this->assertFalse($session->has('remembered'));
        $this->assertNotSame($originalId, $session->getId());
        $this->assertNotSame($originalToken, $session->token());
    }

    #[Test]
    public function over_http_a_deactivated_administrator_is_bounced_from_the_admin_panel(): void
    {
        $admin = $this->makeAdmin();

        $first = $this->actingAs($admin)->get('/admin');

        $this->assertContains($first->status(), [200, 302]);
        $this->assertStringNotContainsString('/login', (string) $first->headers->get('Location'));
        $this->assertAuthenticatedAs($admin);

        $admin->forceFill(['status' => UserStatus::Inactive])->save();

        $this->get('/admin')->assertRedirectContains('/admin/login');

        $this->assertGuest();
    }

    /**
     * A request that carries a started session, as StartSession would have
     * left it by the time this middleware runs.
     */
    private function requestWithSession(): Request
    {
        $session = $this->app->make('session.store');

        $this->assertInstanceOf(Store::class, $session);

        $session->start();

        $request = Request::create('/admin');
        $request->setLaravelSession($session);

        return $request;
    }
}
