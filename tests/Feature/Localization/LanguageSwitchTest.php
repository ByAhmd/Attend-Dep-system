<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The language switch: Arabic by default, English on request, remembered in
 * a cookie so it works before sign-in and survives sign-out.
 */
final class LanguageSwitchTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_interface_is_arabic_and_right_to_left_by_default(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('English');
    }

    #[Test]
    public function a_visitor_can_switch_to_english_before_signing_in(): void
    {
        $this->get('/locale/en', ['Referer' => url('/login')])
            ->assertRedirect(url('/login'))
            ->assertCookie(SetLocale::COOKIE, 'en');

        $this->withCookie(SetLocale::COOKIE, 'en')
            ->get('/login')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('العربية');
    }

    #[Test]
    public function switching_back_to_arabic_works_the_same_way(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'en')
            ->get('/locale/ar', ['Referer' => url('/login')])
            ->assertRedirect(url('/login'))
            ->assertCookie(SetLocale::COOKIE, 'ar');
    }

    #[Test]
    public function the_switch_only_returns_to_this_site(): void
    {
        $this->get('/locale/en', ['Referer' => 'https://evil.example/phish'])
            ->assertRedirect(url('/'));
    }

    #[Test]
    public function an_unknown_language_is_not_found(): void
    {
        $this->get('/locale/fr')->assertNotFound();

        $this->get('/locale/en')->assertCookieMissing('unknown');
    }

    #[Test]
    public function a_forged_cookie_value_leaves_the_default_language_in_place(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'xx')
            ->get('/login')
            ->assertOk()
            ->assertSee('lang="ar"', false);
    }

    #[Test]
    public function a_signed_in_employee_sees_the_switch_and_their_choice_is_honoured(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($employee)
            ->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('English');

        $this->actingAs($employee)
            ->withCookie(SetLocale::COOKIE, 'en')
            ->get('/')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('العربية');
    }

    #[Test]
    public function the_admin_panel_offers_the_switch_too(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('English');

        $this->actingAs($admin)
            ->withCookie(SetLocale::COOKIE, 'en')
            ->get('/admin')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('العربية');
    }

    #[Test]
    public function the_language_is_kept_on_livewire_updates(): void
    {
        // Livewire replays only persistent middleware on component updates;
        // without this, pressing Check In would re-render in the default language.
        $this->assertContains(SetLocale::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }
}
