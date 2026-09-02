<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Filament\PanelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Who may enter which panel - the rule in isolation, then the same rule as
 * deployed behind the real middleware stack.
 *
 * Each HTTP probe is its own test: the guard's session state does not
 * survive a second actingAs() cleanly inside one request cycle, and a
 * fresh application per probe is the condition the guard runs under in
 * production anyway.
 */
final class PanelAccessTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, UserStatus, string, bool}>
     */
    public static function accessMatrix(): array
    {
        return [
            'active employee, employee panel' => [UserRole::Employee, UserStatus::Active, PanelAccess::EMPLOYEE_PANEL_ID, true],
            'active employee, admin panel' => [UserRole::Employee, UserStatus::Active, PanelAccess::ADMIN_PANEL_ID, false],
            'active employee, unknown panel' => [UserRole::Employee, UserStatus::Active, 'billing', false],
            'active admin, employee panel' => [UserRole::Admin, UserStatus::Active, PanelAccess::EMPLOYEE_PANEL_ID, true],
            'active admin, admin panel' => [UserRole::Admin, UserStatus::Active, PanelAccess::ADMIN_PANEL_ID, true],
            'active admin, unknown panel' => [UserRole::Admin, UserStatus::Active, 'billing', false],
            'inactive employee, employee panel' => [UserRole::Employee, UserStatus::Inactive, PanelAccess::EMPLOYEE_PANEL_ID, false],
            'inactive employee, admin panel' => [UserRole::Employee, UserStatus::Inactive, PanelAccess::ADMIN_PANEL_ID, false],
            'inactive employee, unknown panel' => [UserRole::Employee, UserStatus::Inactive, 'billing', false],
            'inactive admin, employee panel' => [UserRole::Admin, UserStatus::Inactive, PanelAccess::EMPLOYEE_PANEL_ID, false],
            'inactive admin, admin panel' => [UserRole::Admin, UserStatus::Inactive, PanelAccess::ADMIN_PANEL_ID, false],
            'inactive admin, unknown panel' => [UserRole::Admin, UserStatus::Inactive, 'billing', false],
        ];
    }

    #[Test]
    #[DataProvider('accessMatrix')]
    public function the_rule_answers_every_role_status_and_panel_combination(
        UserRole $role,
        UserStatus $status,
        string $panelId,
        bool $expected,
    ): void {
        $user = new User(['role' => $role, 'status' => $status]);

        $this->assertSame($expected, PanelAccess::canAccess($user, $panelId));
    }

    #[Test]
    public function can_access_panel_delegates_to_the_rule(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();
        $adminPanel = filament()->getPanel(PanelAccess::ADMIN_PANEL_ID);
        $employeePanel = filament()->getPanel(PanelAccess::EMPLOYEE_PANEL_ID);

        $this->assertTrue($admin->canAccessPanel($adminPanel));
        $this->assertTrue($admin->canAccessPanel($employeePanel));
        $this->assertFalse($employee->canAccessPanel($adminPanel));
        $this->assertTrue($employee->canAccessPanel($employeePanel));
    }

    #[Test]
    public function an_employee_is_forbidden_from_the_admin_panel(): void
    {
        $this->actingAs($this->makeEmployee())
            ->get('/admin')
            ->assertForbidden();
    }

    #[Test]
    public function an_administrator_enters_the_admin_panel(): void
    {
        $admin = $this->makeAdmin();

        // The dashboard answers the panel root directly.
        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertAuthenticatedAs($admin);
    }

    #[Test]
    public function an_employee_enters_the_employee_panel(): void
    {
        $employee = $this->makeEmployee();

        // The attendance screen answers the panel root directly.
        $this->actingAs($employee)->get('/')->assertOk();

        $this->assertAuthenticatedAs($employee);
    }

    #[Test]
    public function a_deactivated_employee_is_signed_out_rather_than_shown_an_error(): void
    {
        $inactive = $this->makeEmployee(status: UserStatus::Inactive);

        $this->actingAs($inactive)
            ->get('/admin')
            ->assertRedirectContains('/admin/login');

        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_employee_is_signed_out_of_the_employee_panel_too(): void
    {
        $inactive = $this->makeEmployee(status: UserStatus::Inactive);

        $this->actingAs($inactive)
            ->get('/')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    #[Test]
    public function a_guest_is_sent_to_the_admin_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function a_guest_is_sent_to_the_employee_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    #[Test]
    public function both_login_pages_are_served(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->get('/login')->assertOk();
    }
}
