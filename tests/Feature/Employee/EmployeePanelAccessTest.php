<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\UserStatus;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Who reaches the employee panel, over real HTTP.
 *
 * These requests walk the panel's actual middleware stack, so they prove the
 * guard as deployed: the sign-out of a deactivated account, the login page's
 * refusal of one, and the wall between an employee and /admin.
 */
final class EmployeePanelAccessTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    #[Test]
    public function an_active_employee_opens_the_attendance_screen_at_the_root(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($employee)
            ->get('/')
            ->assertOk()
            ->assertSee(__('attendance.page.greeting', ['name' => $employee->name]))
            ->assertSee(__('attendance.actions.check_in'));
    }

    #[Test]
    public function an_administrator_may_record_attendance_too(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/')
            ->assertOk();
    }

    #[Test]
    public function signing_in_lands_on_the_attendance_screen(): void
    {
        $employee = $this->makeEmployee(password: 'secret');

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $employee->email,
                'password' => 'secret',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($employee);
    }

    #[Test]
    public function an_inactive_employee_cannot_sign_in(): void
    {
        $inactive = $this->makeEmployee(status: UserStatus::Inactive, password: 'secret');

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $inactive->email,
                'password' => 'secret',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    #[Test]
    public function an_employee_deactivated_after_signing_in_is_signed_out_on_the_next_request(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        $employee->forceFill(['status' => UserStatus::Inactive])->save();

        $this->get('/')->assertRedirect('/login');

        $this->assertGuest();
    }

    #[Test]
    public function an_employee_is_refused_the_admin_panel(): void
    {
        $this->actingAs($this->makeEmployee())
            ->get('/admin')
            ->assertForbidden();
    }
}
