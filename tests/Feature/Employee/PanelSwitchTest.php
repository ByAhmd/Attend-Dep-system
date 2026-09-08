<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Http\Middleware\SetLocale;
use App\Support\Filament\PanelAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Neither panel is a dead end for the people who belong in both.
 *
 * An administrator has two screens in this product: the attendance screen at
 * the site root, where they record their own attendance like everybody else,
 * and the administration panel at /admin. Signing in at the root - which is
 * what the company's address does - used to land them on a screen that
 * mentioned /admin nowhere, and /admin offered nothing that led back. Both
 * doors existed and neither had a handle.
 *
 * The entry is in the user menu on each side, and the employee side is
 * conditional: an ordinary employee must not be shown a door that
 * EnsureAccountIsActive and the panel guard would then close in their face.
 */
final class PanelSwitchTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCompanyLocation();
    }

    #[Test]
    public function an_administrator_on_the_attendance_screen_is_shown_the_way_to_the_admin_panel(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get('/');

        $response->assertOk()
            ->assertSee(__('app.panels.admin'))
            ->assertSee(Filament::getPanel(PanelAccess::ADMIN_PANEL_ID)->getUrl(), false);
    }

    #[Test]
    public function an_ordinary_employee_is_not_offered_a_door_they_cannot_open(): void
    {
        $this->actingAs($this->makeEmployee())
            ->get('/')
            ->assertOk()
            ->assertDontSee(__('app.panels.admin'))
            ->assertDontSee('/admin');
    }

    #[Test]
    public function an_administrator_in_the_admin_panel_is_shown_the_way_to_their_own_attendance(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get('/admin');

        $response->assertOk()
            ->assertSee(__('app.panels.employee'));
    }

    #[Test]
    public function the_two_entries_are_labelled_in_english_too(): void
    {
        $admin = $this->makeAdmin();

        $this->withCookie(SetLocale::COOKIE, 'en');

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Dashboard');
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('My attendance');
    }
}
